const Joi = require("joi");

const constactSchema = Joi.object({
    name: Joi.string().trim().min(3).max(50).required(),
    number: Joi.string()
        .trim()
        .min(10)
        .max(10)
        .pattern(/^[0-9]+$/)
        .required()
        .messages({
            "string.min": "Phone number must be 10 digits",
            "string.max": "Phone number must be only 10 digits",
            "string.pattern.base": "Phone number must contain only digits",
            "string.empty": "Phone number is required",
            "any.required": "Phone number is required",
        }),
    course: Joi.string().trim().required(),
    message: Joi.string().trim().max(1000).allow("")
})

module.exports = constactSchema