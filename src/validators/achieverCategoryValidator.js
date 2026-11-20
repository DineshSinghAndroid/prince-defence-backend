
const Joi = require("joi");

const createCategorySchema = Joi.object({
    name: Joi.string().trim().min(2).max(100).required(),
    isActive: Joi.boolean().optional(),
    order: Joi.number().integer().min(0).optional(),
});

const updateCategorySchema = Joi.object({
    name: Joi.string().trim().min(2).max(100),
    isActive: Joi.boolean(),
    order: Joi.number().integer().min(0),
}).min(1);

const reorderCategoriesSchema = Joi.object({
    orderedIds: Joi.array()
        .items(Joi.string().required())
        .min(1)
        .required(),
});

module.exports = {
    createCategorySchema,
    updateCategorySchema,
    reorderCategoriesSchema,
};
