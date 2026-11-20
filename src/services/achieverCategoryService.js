
const AchieverCategory = require("../models/AchieverCategory");
const Achiever = require("../models/Achiever");
const ApiError = require("../utils/ApiError");

const createSlug = (name) => {
    return name
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, "")
        .replace(/\s+/g, "-")
        .replace(/-+/g, "-");
};

const generateUniqueSlug = async (name, excludeId = null) => {
    const baseSlug = createSlug(name) || "category";
    let slug = baseSlug;
    let count = 1;

    while (
        await AchieverCategory.findOne({
            slug,
            ...(excludeId ? { _id: { $ne: excludeId } } : {}),
        })
    ) {
        slug = `${baseSlug}-${count}`;
        count++;
    }

    return slug;
};

const createCategory = async (data) => {
    const slug = await generateUniqueSlug(data.name);

    let order = data.order;
    if (order === undefined) {
        const lastCategory = await AchieverCategory.findOne().sort({ order: -1 }).select("order");
        order = lastCategory && typeof lastCategory.order === "number" ? lastCategory.order + 1 : 0;
    }

    try {
        return await AchieverCategory.create({
            name: data.name,
            slug,
            isActive: data.isActive ?? true,
            order,
        });
    } catch (error) {
        if (error.code === 11000) {
            throw new ApiError(409, "Category already exists.");
        }
        throw error;
    }
};

const getCategories = async (includeInactive = false) => {
    const filter = includeInactive ? {} : { isActive: true };

    return AchieverCategory.find(filter).sort({ order: 1, createdAt: 1 });
};

const updateCategory = async (id, data) => {
    const category = await AchieverCategory.findById(id);

    if (!category) {
        throw new ApiError(404, "Achiever category not found.");
    }

    if (data.name !== undefined) {
        category.name = data.name;
        category.slug = await generateUniqueSlug(data.name, category._id);
    }

    if (data.isActive !== undefined) {
        category.isActive = data.isActive;
    }

    if (data.order !== undefined) {
        category.order = Number(data.order);
    }

    try {
        await category.save();
        return category;
    } catch (error) {
        if (error.code === 11000) {
            throw new ApiError(409, "Category already exists.");
        }
        throw error;
    }
};

const reorderCategories = async (orderedIds) => {
    if (!Array.isArray(orderedIds) || orderedIds.length === 0) {
        throw new ApiError(400, "orderedIds must be a non-empty array of IDs.");
    }

    const bulkOps = orderedIds.map((id, index) => ({
        updateOne: {
            filter: { _id: id },
            update: { $set: { order: index } },
        },
    }));

    await AchieverCategory.bulkWrite(bulkOps);

    return getCategories(true);
};

const deleteCategory = async (id) => {
    const category = await AchieverCategory.findById(id);

    if (!category) {
        throw new ApiError(404, "Achiever category not found.");
    }

    const studentCount = await Achiever.countDocuments({
        categoryId: category._id,
    });

    if (studentCount > 0) {
        throw new ApiError(
            409,
            "This category contains students. Remove or reassign them first."
        );
    }

    await category.deleteOne();

    return { id: category._id };
};

module.exports = {
    createCategory,
    getCategories,
    updateCategory,
    deleteCategory,
    reorderCategories,
};
