const mongoose = require("mongoose");
require("dotenv").config({ path: require("path").resolve(__dirname, "../../.env") });
const Achiever = require("../models/Achiever");

async function run() {
    try {
        await mongoose.connect(process.env.MONGODB_URI);
        console.log("Connected to MongoDB");

        const docs = await Achiever.collection
            .find({ createdAt: { $exists: false } })
            .project({ _id: 1 })
            .toArray();
        console.log(`Found ${docs.length} achievers without createdAt`);

        if (docs.length > 0) {
            const bulkOps = docs.map((doc) => ({
                updateOne: {
                    filter: { _id: doc._id },
                    update: {
                        $set: {
                            createdAt: doc._id.getTimestamp(),
                        },
                    },
                },
            }));

            const result = await Achiever.collection.bulkWrite(bulkOps);
            console.log(`Updated ${result.modifiedCount} achievers.`);
        }

        const remaining = await Achiever.collection.countDocuments({
            createdAt: { $exists: false },
        });
        console.log(`Remaining achievers without createdAt: ${remaining}`);
    } catch (err) {
        console.error("Migration error:", err);
    } finally {
        await mongoose.disconnect();
        console.log("Disconnected");
    }
}

run();
