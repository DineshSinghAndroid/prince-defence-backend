const Database = require("better-sqlite3");
const { Query, Aggregator } = require("mingo");
const crypto = require("crypto");
const path = require("path");
const fs = require("fs");

function generateObjectId() {
    return crypto.randomBytes(12).toString("hex");
}

const dbDir = path.resolve(__dirname, "../../data");
if (!fs.existsSync(dbDir)) {
    fs.mkdirSync(dbDir, { recursive: true });
}

const dbFilePath = path.join(dbDir, "database.sqlite");
const sqliteDb = new Database(dbFilePath);
sqliteDb.pragma("journal_mode = WAL");
sqliteDb.pragma("synchronous = NORMAL");

const modelsRegistry = {};

function applyProjection(doc, selectStr) {
    if (!doc || !selectStr || typeof selectStr !== "string") return doc;
    const tokens = selectStr.trim().split(/\s+/).filter(Boolean);
    if (!tokens.length) return doc;

    const hasExclusions = tokens.some((t) => t.startsWith("-"));
    const copy = { ...doc };

    if (hasExclusions) {
        tokens.forEach((t) => {
            if (t.startsWith("-")) {
                delete copy[t.slice(1)];
            }
        });
        return copy;
    }

    const projected = {};
    if (tokens.includes("_id") || !tokens.includes("-id")) {
        projected._id = copy._id;
        projected.id = copy._id;
    }
    tokens.forEach((t) => {
        if (copy[t] !== undefined) {
            projected[t] = copy[t];
        }
    });
    return projected;
}

function wrapDoc(rawDoc, tableName) {
    if (!rawDoc) return null;
    const docData = typeof rawDoc.data === "string" ? JSON.parse(rawDoc.data) : (rawDoc.data || rawDoc);
    const id = String(rawDoc._id || docData._id || generateObjectId());
    const createdAt = new Date(rawDoc.createdAt || docData.createdAt || Date.now());
    const updatedAt = new Date(rawDoc.updatedAt || docData.updatedAt || Date.now());

    const target = {
        ...docData,
        _id: id,
        id: id,
        createdAt,
        updatedAt,
    };

    const docInstance = new Proxy(target, {
        get(t, prop) {
            if (prop === "_id" || prop === "id") return t._id;
            if (prop === "toObject" || prop === "toJSON") {
                return () => {
                    const obj = { ...t };
                    return obj;
                };
            }
            if (prop === "toString") {
                return () => JSON.stringify(t);
            }
            if (prop === "isModified") {
                return () => true;
            }
            if (prop === "save") {
                return async () => {
                    t.updatedAt = new Date();
                    const cleanData = { ...t };
                    delete cleanData.id;
                    const stmt = sqliteDb.prepare(`
                        INSERT INTO "${tableName}" (_id, data, createdAt, updatedAt)
                        VALUES (?, ?, ?, ?)
                        ON CONFLICT(_id) DO UPDATE SET
                            data = excluded.data,
                            updatedAt = excluded.updatedAt
                    `);
                    stmt.run(
                        t._id,
                        JSON.stringify(cleanData),
                        t.createdAt.toISOString(),
                        t.updatedAt.toISOString()
                    );
                    return docInstance;
                };
            }
            return t[prop];
        },
        set(t, prop, value) {
            t[prop] = value;
            return true;
        },
    });

    return docInstance;
}

class ModelQuery {
    constructor(tableName, filter = {}, single = false) {
        this.tableName = tableName;
        this._filter = filter;
        this._sort = null;
        this._skip = 0;
        this._limit = null;
        this._select = null;
        this._populateFields = [];
        this._isLean = false;
        this._single = single;
    }

    sort(s) {
        this._sort = s;
        return this;
    }

    skip(n) {
        this._skip = Math.max(0, Number(n) || 0);
        return this;
    }

    limit(n) {
        this._limit = n != null ? Math.max(0, Number(n) || 0) : null;
        return this;
    }

    select(fields) {
        this._select = fields;
        return this;
    }

    populate(pathOrOptions, select) {
        if (typeof pathOrOptions === "object" && pathOrOptions !== null) {
            this._populateFields.push({
                path: pathOrOptions.path,
                select: pathOrOptions.select,
            });
        } else {
            this._populateFields.push({ path: pathOrOptions, select });
        }
        return this;
    }

    lean() {
        this._isLean = true;
        return this;
    }

    async _execute() {
        const rows = sqliteDb
            .prepare(`SELECT _id, data, createdAt, updatedAt FROM "${this.tableName}"`)
            .all();

        let docs = rows.map((r) => {
            const parsed = JSON.parse(r.data);
            return {
                ...parsed,
                _id: String(r._id),
                id: String(r._id),
                createdAt: new Date(r.createdAt),
                updatedAt: new Date(r.updatedAt),
            };
        });

        if (this._filter && Object.keys(this._filter).length > 0) {
            const q = new Query(this._filter);
            docs = q.find(docs).all();
        }

        if (this._sort) {
            const sortSpec = {};
            if (typeof this._sort === "string") {
                this._sort.split(/\s+/).forEach((f) => {
                    if (!f) return;
                    if (f.startsWith("-")) sortSpec[f.slice(1)] = -1;
                    else sortSpec[f] = 1;
                });
            } else if (typeof this._sort === "object") {
                Object.assign(sortSpec, this._sort);
            }
            docs.sort((a, b) => {
                for (const [key, dir] of Object.entries(sortSpec)) {
                    const valA = a[key] instanceof Date ? a[key].getTime() : a[key];
                    const valB = b[key] instanceof Date ? b[key].getTime() : b[key];
                    if (valA < valB) return dir === -1 ? 1 : -1;
                    if (valA > valB) return dir === -1 ? -1 : 1;
                }
                return 0;
            });
        }

        if (this._skip > 0) {
            docs = docs.slice(this._skip);
        }

        if (this._limit != null) {
            docs = docs.slice(0, this._limit);
        }

        for (const pop of this._populateFields) {
            const targetModel =
                modelsRegistry[pop.path] ||
                modelsRegistry[pop.path.replace(/Id$/i, "")] ||
                Object.values(modelsRegistry).find(
                    (m) =>
                        m.modelName.toLowerCase() ===
                        pop.path.toLowerCase().replace(/id$/i, "")
                );

            if (targetModel) {
                for (const d of docs) {
                    if (d[pop.path]) {
                        const refId = String(d[pop.path]);
                        const popDoc = await targetModel.findById(refId).lean();
                        if (popDoc) {
                            if (pop.select) {
                                d[pop.path] = applyProjection(popDoc, pop.select);
                            } else {
                                d[pop.path] = popDoc;
                            }
                        }
                    }
                }
            }
        }

        if (this._select) {
            docs = docs.map((d) => applyProjection(d, this._select));
        }

        if (this._single) {
            const singleDoc = docs[0] || null;
            return this._isLean ? singleDoc : wrapDoc(singleDoc, this.tableName);
        }

        return this._isLean ? docs : docs.map((d) => wrapDoc(d, this.tableName));
    }

    exec() {
        return this._execute();
    }

    then(onFulfilled, onRejected) {
        return this._execute().then(onFulfilled, onRejected);
    }

    catch(onRejected) {
        return this._execute().catch(onRejected);
    }
}

function applySchemaDefaults(schema, doc) {
    if (!schema?.definition || typeof schema.definition !== "object") return doc;
    const result = { ...doc };
    for (const [key, def] of Object.entries(schema.definition)) {
        if (result[key] === undefined && def && typeof def === "object") {
            if ("default" in def) {
                result[key] = typeof def.default === "function" ? def.default() : def.default;
            }
        }
    }
    return result;
}

function createModelClass(name, schema) {
    const tableName = (
        schema?.options?.collection || `${name.toLowerCase()}s`
    ).replace(/[^a-zA-Z0-9_]/g, "_");

    sqliteDb.exec(`
        CREATE TABLE IF NOT EXISTS "${tableName}" (
            _id TEXT PRIMARY KEY,
            data TEXT NOT NULL,
            createdAt TEXT NOT NULL,
            updatedAt TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS "idx_${tableName}_createdAt" ON "${tableName}" (createdAt);
        CREATE INDEX IF NOT EXISTS "idx_${tableName}_updatedAt" ON "${tableName}" (updatedAt);
    `);

    class Model {
        constructor(docData = {}) {
            return wrapDoc(applySchemaDefaults(schema, docData), tableName);
        }

        static get modelName() {
            return name;
        }

        static get schema() {
            return schema;
        }

        static async create(doc) {
            if (Array.isArray(doc)) {
                return Model.insertMany(doc);
            }
            const withDefaults = applySchemaDefaults(schema, doc);
            const now = new Date();
            const id = String(withDefaults._id || generateObjectId());
            const data = {
                ...withDefaults,
                _id: id,
                createdAt: withDefaults.createdAt ? new Date(withDefaults.createdAt) : now,
                updatedAt: withDefaults.updatedAt ? new Date(withDefaults.updatedAt) : now,
            };
            delete data.id;

            const stmt = sqliteDb.prepare(`
                INSERT INTO "${tableName}" (_id, data, createdAt, updatedAt)
                VALUES (?, ?, ?, ?)
            `);
            stmt.run(
                id,
                JSON.stringify(data),
                data.createdAt.toISOString(),
                data.updatedAt.toISOString()
            );
            return wrapDoc(data, tableName);
        }

        static async insertMany(docs) {
            const results = [];
            for (const d of docs) {
                results.push(await Model.create(d));
            }
            return results;
        }

        static find(filter = {}) {
            return new ModelQuery(tableName, filter, false);
        }

        static findOne(filter = {}) {
            return new ModelQuery(tableName, filter, true);
        }

        static findById(id) {
            return Model.findOne({ _id: String(id) });
        }

        static async countDocuments(filter = {}) {
            if (!filter || Object.keys(filter).length === 0) {
                const res = sqliteDb
                    .prepare(`SELECT COUNT(*) as count FROM "${tableName}"`)
                    .get();
                return res.count;
            }
            const rows = sqliteDb
                .prepare(`SELECT _id, data, createdAt, updatedAt FROM "${tableName}"`)
                .all();
            const docs = rows.map((r) => ({
                ...JSON.parse(r.data),
                _id: String(r._id),
                createdAt: new Date(r.createdAt),
                updatedAt: new Date(r.updatedAt),
            }));
            const q = new Query(filter);
            return q.find(docs).all().length;
        }

        static async findByIdAndUpdate(id, update, options = {}) {
            return Model.findOneAndUpdate({ _id: String(id) }, update, options);
        }

        static async findOneAndUpdate(filter, update, options = {}) {
            let doc = await Model.findOne(filter);
            if (!doc) {
                if (options.upsert) {
                    const newDoc = {
                        ...(options.setDefaultsOnInsert ? {} : {}),
                        ...filter,
                    };
                    if (update.$set) Object.assign(newDoc, update.$set);
                    if (update.$setOnInsert) Object.assign(newDoc, update.$setOnInsert);
                    return Model.create(newDoc);
                }
                return null;
            }

            let data = doc.toObject();
            if (Array.isArray(update)) {
                for (const stage of update) {
                    if (stage.$set) {
                        for (const [key, expr] of Object.entries(stage.$set)) {
                            if (expr && typeof expr === "object") {
                                if (expr.$cond) {
                                    const [cond, trueVal, falseVal] = expr.$cond;
                                    const q = new Query(cond);
                                    const matches = q.test(data);
                                    data[key] = matches ? trueVal : falseVal;
                                } else if (expr.$add) {
                                    let sum = 0;
                                    for (const operand of expr.$add) {
                                        if (typeof operand === "number") sum += operand;
                                        else if (
                                            typeof operand === "string" &&
                                            operand.startsWith("$")
                                        ) {
                                            sum += Number(data[operand.slice(1)]) || 0;
                                        } else if (operand && operand.$ifNull) {
                                            const [valField, fallback] = operand.$ifNull;
                                            const v = data[valField.slice(1)];
                                            sum += v != null ? Number(v) : fallback;
                                        }
                                    }
                                    data[key] = sum;
                                }
                            } else {
                                data[key] = expr;
                            }
                        }
                    }
                }
            } else {
                if (update.$set) Object.assign(data, update.$set);
                if (update.$setOnInsert && !doc) Object.assign(data, update.$setOnInsert);
                if (update.$inc) {
                    for (const [k, v] of Object.entries(update.$inc)) {
                        data[k] = (Number(data[k]) || 0) + v;
                    }
                }
                if (update.$push) {
                    for (const [k, v] of Object.entries(update.$push)) {
                        if (!Array.isArray(data[k])) data[k] = [];
                        data[k].push(v);
                    }
                }
                if (update.$pull) {
                    for (const [k, v] of Object.entries(update.$pull)) {
                        if (Array.isArray(data[k])) {
                            data[k] = data[k].filter((item) => item !== v && item?._id !== v);
                        }
                    }
                }
                if (
                    !update.$set &&
                    !update.$inc &&
                    !update.$push &&
                    !update.$pull &&
                    !update.$setOnInsert
                ) {
                    Object.assign(data, update);
                }
            }

            data.updatedAt = new Date();
            delete data.id;

            sqliteDb
                .prepare(
                    `UPDATE "${tableName}" SET data = ?, updatedAt = ? WHERE _id = ?`
                )
                .run(JSON.stringify(data), data.updatedAt.toISOString(), data._id);

            return wrapDoc(data, tableName);
        }

        static async findByIdAndDelete(id) {
            return Model.findOneAndDelete({ _id: String(id) });
        }

        static async findOneAndDelete(filter) {
            const doc = await Model.findOne(filter);
            if (!doc) return null;
            sqliteDb
                .prepare(`DELETE FROM "${tableName}" WHERE _id = ?`)
                .run(doc._id);
            return doc;
        }

        static async deleteMany(filter = {}) {
            if (!filter || Object.keys(filter).length === 0) {
                const res = sqliteDb.prepare(`DELETE FROM "${tableName}"`).run();
                return { deletedCount: res.changes, acknowledged: true };
            }
            const docs = await Model.find(filter).lean();
            const ids = docs.map((d) => d._id);
            if (ids.length > 0) {
                const placeholders = ids.map(() => "?").join(",");
                const res = sqliteDb
                    .prepare(`DELETE FROM "${tableName}" WHERE _id IN (${placeholders})`)
                    .run(...ids);
                return { deletedCount: res.changes, acknowledged: true };
            }
            return { deletedCount: 0, acknowledged: true };
        }

        static async deleteOne(filter = {}) {
            const doc = await Model.findOne(filter);
            if (!doc) return { deletedCount: 0, acknowledged: true };
            sqliteDb.prepare(`DELETE FROM "${tableName}" WHERE _id = ?`).run(doc._id);
            return { deletedCount: 1, acknowledged: true };
        }

        static async updateOne(filter, update, options = {}) {
            const res = await Model.findOneAndUpdate(filter, update, options);
            return { modifiedCount: res ? 1 : 0, acknowledged: true };
        }

        static async updateMany(filter, update, options = {}) {
            const docs = await Model.find(filter);
            let modified = 0;
            for (const d of docs) {
                await Model.findOneAndUpdate({ _id: d._id }, update, options);
                modified++;
            }
            return { modifiedCount: modified, acknowledged: true };
        }

        static async aggregate(pipeline = []) {
            const rows = sqliteDb
                .prepare(`SELECT _id, data, createdAt, updatedAt FROM "${tableName}"`)
                .all();
            const docs = rows.map((r) => ({
                ...JSON.parse(r.data),
                _id: String(r._id),
                createdAt: new Date(r.createdAt),
                updatedAt: new Date(r.updatedAt),
            }));

            // Normalize simple pipelines if needed
            const normalizedPipeline = pipeline.map((stage) => {
                if (stage.$sample && stage.$sample.size) {
                    return null;
                }
                return stage;
            }).filter(Boolean);

            const agg = new Aggregator(normalizedPipeline);
            let result = agg.run(docs);

            const sampleStage = pipeline.find((s) => s.$sample && s.$sample.size);
            if (sampleStage) {
                const size = sampleStage.$sample.size;
                result = result.sort(() => Math.random() - 0.5).slice(0, size);
            }

            return result;
        }
    }

    modelsRegistry[name] = Model;
    return Model;
}

const SchemaClass = class Schema {
    constructor(definition = {}, options = {}) {
        this.definition = definition;
        this.options = options;
        this.indexes = [];
    }
    index(fields, options) {
        this.indexes.push({ fields, options });
    }
};

const ObjectIdHelper = Object.assign(
    (id) => (id ? String(id) : generateObjectId()),
    {
        isValid: (id) =>
            typeof id === "string" &&
            (/^[0-9a-fA-F]{24}$/.test(id) || id.length > 0),
    }
);

SchemaClass.Types = {
    ObjectId: ObjectIdHelper,
    String,
    Number,
    Boolean,
    Date,
    Array,
    Mixed: Object,
    Map: Object,
    Buffer,
};

const mongooseMock = {
    connect: async () => {
        console.log("SQLite Database Connected Successfully at " + dbFilePath);
        return true;
    },
    connection: {
        readyState: 1,
        on: () => {},
        once: (evt, cb) => cb && cb(),
    },
    models: modelsRegistry,
    model: (name, schema) => {
        if (modelsRegistry[name]) return modelsRegistry[name];
        return createModelClass(name, schema);
    },
    Schema: SchemaClass,
    Types: {
        ObjectId: ObjectIdHelper,
    },
    mongoose: null,
};
mongooseMock.mongoose = mongooseMock;

module.exports = mongooseMock;
