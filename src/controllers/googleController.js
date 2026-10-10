const {
    getGoogleAuthUrl,
    getGoogleTokens,
    getGoogleAccounts,
} = require("../services/googleService");


// Start Google OAuth

const googleAuth = (req, res) => {
    try {
        const authUrl = getGoogleAuthUrl();

        return res.redirect(authUrl);
    } catch (error) {
        console.error(
            "Google auth error:",
            error
        );

        return res.status(500).json({
            success: false,
            message: "Failed to start Google authorization",
        });
    }
};


// Google OAuth callback

const googleCallback = async (
    req,
    res,
    next
) => {
    try {
        console.log(
            "Google callback query:",
            req.query
        );

        const {
            code,
            error,
            error_description,
        } = req.query;

        if (error) {
            return res.status(400).json({
                success: false,
                message:
                    error_description || error,
            });
        }

        if (!code) {
            return res.status(400).json({
                success: false,
                message:
                    "Google authorization code is missing",
            });
        }

        const tokens =
            await getGoogleTokens(code);

        console.log(
            "Google authorization successful"
        );

        console.log(
            "Access token:",
            tokens.access_token
                ? "Received"
                : "Missing"
        );

        console.log(
            "Refresh token:",
            tokens.refresh_token
                ? "Received"
                : "Missing"
        );

        return res.status(200).json({
            success: true,
            message:
                "Google authorization successful",
            hasAccessToken:
                Boolean(tokens.access_token),
            hasRefreshToken:
                Boolean(tokens.refresh_token),
        });
    } catch (error) {
        console.error(
            "Google callback error:",
            error
        );

        next(error);
    }
};


// Get Google Business accounts

const googleAccounts = async (
    req,
    res,
    next
) => {
    try {
        const accounts =
            await getGoogleAccounts();

        return res.status(200).json({
            success: true,
            accounts,
        });
    } catch (error) {
        console.error(
            "Google accounts error:",
            error
        );

        next(error);
    }
};


module.exports = {
    googleAuth,
    googleCallback,
    googleAccounts,
};