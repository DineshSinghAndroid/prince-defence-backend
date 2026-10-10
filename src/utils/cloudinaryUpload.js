const cloudinary = require("../config/cloudinary");

const uploadImage = (
    buffer,
    folder = "defence-academy/blog"
) => {
    return new Promise((resolve, reject) => {
        const uploadStream =
            cloudinary.uploader.upload_stream(
                {
                    folder,
                    resource_type: "image",
                },
                (error, result) => {
                    if (error) {
                        return reject(error);
                    }

                    resolve({
                        url: result.secure_url,
                        publicId: result.public_id,
                    });
                }
            );

        uploadStream.end(buffer);
    });
};

const deleteImage = async (publicId) => {
    if (!publicId) {
        return;
    }

    await cloudinary.uploader.destroy(
        publicId,
        {
            resource_type: "image",
        }
    );
};

module.exports = {
    uploadImage,
    deleteImage,
};