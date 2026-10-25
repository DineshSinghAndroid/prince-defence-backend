const {
    createGalleryCategory,
    getGalleryCategories,
    getGalleryCategoryById,
    updateGalleryCategory,
    updateGalleryCategoryStatus,
    deleteGalleryCategory,
    reorderGalleryCategories,
} = require("../services/galleryCategoryService");

const addGalleryCategory = async (req, res) => {
   
    const category = await createGalleryCategory(req.body);
    res.status(201).json({
        success: true,
        message: "Gallery category created successfully",
        data: category,
    });
};

const fetchGalleryCategories = async (req, res) => {
    const categories = await getGalleryCategories({
        includeGallerySummary: req.query.includeGallerySummary === "true",
    });

    res.status(200).json({
        success: true,
        message: "Gallery categories fetched successfully",
        data: categories,
    });
};

const fetchGalleryCategoryById = async (req, res) => {
    const category = await getGalleryCategoryById(
        req.params.id
    );

    res.status(200).json({
        success: true,
        message: "Gallery category fetched successfully",
        data: category,
    });
};

const editGalleryCategory = async (req, res) => {
    const category = await updateGalleryCategory(
        req.params.id,
        req.body
    );

    res.status(200).json({
        success: true,
        message: "Gallery category updated successfully",
        data: category,
    });
};

const changeGalleryCategoryStatus = async (req, res) => {
    const category = await updateGalleryCategoryStatus(
        req.params.id,
        req.body.isActive
    );

    res.status(200).json({
        success: true,
        message: "Gallery category status updated successfully",
        data: category,
    });
};

const removeGalleryCategory = async (req, res) => {
    const category = await deleteGalleryCategory(
        req.params.id
    );

    res.status(200).json({
        success: true,
        message: "Gallery category deleted successfully",
        data: category,
    });
};

const reorderCategories = async (req, res) => {
    const categories = await reorderGalleryCategories(
        req.body.orderedIds
    );

    res.status(200).json({
        success: true,
        message: "Gallery categories reordered successfully",
        data: categories,
    });
};

module.exports = {
    addGalleryCategory,
    fetchGalleryCategories,
    fetchGalleryCategoryById,
    editGalleryCategory,
    changeGalleryCategoryStatus,
    removeGalleryCategory,
    reorderCategories,
};
