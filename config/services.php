<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // WhatsApp Cloud API (Meta): messages to patients from approved templates
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),                     // System User permanent token
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'), // the sending number
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'), // WABA: where the templates live
        'app_secret' => env('WHATSAPP_APP_SECRET'),           // checks the webhook signature
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),       // chosen by us, typed in Meta's webhook form
        'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'), // changed only to test against a fake
        'country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '213'),
        // The foundation's WhatsApp number: the one registered in Meta (API) and
        // the one to be logged in when sending by hand (manual mode)
        'sender_number' => env('WHATSAPP_SENDER_NUMBER', '+213772712595'),
    ],

    // Public site (links sent to patients, e.g. the evaluation form)
    'frontend_url' => env('FRONTEND_URL', 'https://tagemi-foundation.org'),

];
