const Module = require("module");
const sqliteDb = require("./sqliteDb");

const origRequire = Module.prototype.require;
Module.prototype.require = function (request) {
    if (request === "mongoose") {
        return sqliteDb;
    }
    return origRequire.apply(this, arguments);
};

module.exports = sqliteDb;
