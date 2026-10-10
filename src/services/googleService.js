const { google } = require("googleapis");

const GoogleToken = require("../models/GoogleToken");

const oauth2Client = new google.auth.OAuth2(
    process.env.GOOGLE_CLIENT_ID,
    process.env.GOOGLE_CLIENT_SECRET,
    process.env.GOOGLE_REDIRECT_URI
);

const GOOGLE_SCOPES = [
    "https://www.googleapis.com/auth/business.manage",
];


// Generate Google OAuth URL

const getGoogleAuthUrl = () => {
    const authUrl = oauth2Client.generateAuthUrl({
        access_type: "offline",
        scope: GOOGLE_SCOPES,
        prompt: "consent",
    });

    console.log(
        "GOOGLE_REDIRECT_URI:",
        process.env.GOOGLE_REDIRECT_URI
    );

    console.log(
        "Google Auth URL:",
        authUrl
    );

    return authUrl;
};


// Get Google tokens

const getGoogleTokens = async (code) => {
    const { tokens } =
        await oauth2Client.getToken(code);

    // Check refresh token

    if (!tokens.refresh_token) {
        throw new Error(
            "Google refresh token was not received"
        );
    }

    // Save / update tokens

    await GoogleToken.findOneAndUpdate(
        {
            provider: "google-business",
        },
        {
            provider: "google-business",
            accessToken: tokens.access_token,
            refreshToken: tokens.refresh_token,
            expiryDate: tokens.expiry_date
                ? new Date(tokens.expiry_date)
                : null,
        },
        {
            upsert: true,
            returnDocument: "after",
        }
    );

    return tokens;
};


// Get Google Business Profile accounts

const getGoogleAccounts = async () => {
    const token = await GoogleToken.findOne({
        provider: "google-business",
    });

    if (!token) {
        throw new Error(
            "Google authorization not found"
        );
    }

    // Set saved credentials

    oauth2Client.setCredentials({
        access_token: token.accessToken,
        refresh_token: token.refreshToken,
        expiry_date: token.expiryDate
            ? token.expiryDate.getTime()
            : undefined,
    });

    // Google Business Profile Account Management API

    const mybusinessAccountManagement =
        google.mybusinessaccountmanagement({
            version: "v1",
            auth: oauth2Client,
        });

    const response =
        await mybusinessAccountManagement.accounts.list();

    return response.data.accounts || [];
};


module.exports = {
    getGoogleAuthUrl,
    getGoogleTokens,
    getGoogleAccounts,
};