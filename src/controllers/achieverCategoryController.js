
const achieverCategoryService = require("../services/achieverCategoryService");

const createCategory = async (req, res) => {
    const category = await achieverCategoryService.createCategory(req.body);

    res.status(201).json({
        success: true,
        message: "Achiever category created successfully.",
        data: category,
    });
};

const getCategories = async (req, res) => {
    const includeInactive = req.query.includeInactive === "true";

    const categories = await achieverCategoryService.getCategories(
        includeInactive
    );

    // Cache-Control header for public queries (5 min CDN/browser cache)
    if (!includeInactive) {
        res.set("Cache-Control", "public, max-age=300, stale-while-revalidate=600");
    }

    res.status(200).json({
        success: true,
        message: "Achiever categories fetched successfully.",
        data: categories,
    });
};

const updateCategory = async (req, res) => {
    const category = await achieverCategoryService.updateCategory(
        req.params.id,
        req.body
    );

    res.status(200).json({
        success: true,
        message: "Achiever category updated successfully.",
        data: category,
    });
};

const deleteCategory = async (req, res) => {
    const result = await achieverCategoryService.deleteCategory(req.params.id);

    res.status(200).json({
        success: true,
        message: "Achiever category deleted successfully.",
        data: result,
    });
};

const reorderCategories = async (req, res) => {
    const categories = await achieverCategoryService.reorderCategories(
        req.body.orderedIds
    );

    res.status(200).json({
        success: true,
        message: "Achiever categories reordered successfully.",
        data: categories,
    });
};

module.exports = {
    createCategory,
    getCategories,
    updateCategory,
    deleteCategory,
    reorderCategories,
};
