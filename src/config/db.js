require("./sqliteHook");
const sqliteDb = require("./sqliteDb");

const connectDB = async () => {
    try {
        await sqliteDb.connect();
    } catch (error) {
        console.error("SQLite Connection Failed:", error.message);
        process.exit(1);
    }
};

module.exports = connectDB;