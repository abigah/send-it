<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Channel
    |--------------------------------------------------------------------------
    |
    | The channel used to send an entry when one isn't explicitly chosen.
    | Additional channels (postmark, sms, whatsapp, ...) can be registered
    | via the ChannelManager and selected at send time.
    |
    */

    'default' => env('SEND_IT_CHANNEL', 'mailchimp'),

    /*
    |--------------------------------------------------------------------------
    | Email Layout
    |--------------------------------------------------------------------------
    |
    | Channel content is wrapped in this Antlers view before sending. Publish
    | the view to resources/views/vendor/send-it/ to customise it, or point
    | "layout" at your own view. Set "layout" to null to send content as-is.
    |
    */

    'email' => [
        'layout' => 'send-it::default-email.layout',

        // Absolute URL to the logo shown centered at the top of the email.
        'logo_url' => env('SEND_IT_EMAIL_LOGO_URL'),
        'logo_width' => env('SEND_IT_EMAIL_LOGO_WIDTH', 160),

        'site_name' => env('SEND_IT_EMAIL_SITE_NAME', env('APP_NAME')),
        'site_url' => env('SEND_IT_EMAIL_SITE_URL', env('APP_URL')),

        // Article header: the entry field holding the author(s) and the date
        // format shown in the byline.
        'author_field' => env('SEND_IT_EMAIL_AUTHOR_FIELD', 'author'),
        'date_format' => env('SEND_IT_EMAIL_DATE_FORMAT', 'F j, Y'),

        // Footer. "company" is shown in the copyright line; "site_name" is
        // used as a fallback. The unsubscribe / update-preferences links below
        // are used by the mailer channel; the Mailchimp channel replaces them
        // with the required *|UNSUB|* and *|UPDATE_PROFILE|* merge tags.
        'company' => env('SEND_IT_EMAIL_COMPANY', env('APP_NAME')),
        'footer_text' => env('SEND_IT_EMAIL_FOOTER_TEXT'),
        'footer_address' => env('SEND_IT_EMAIL_FOOTER_ADDRESS'),
        'unsubscribe_url' => env('SEND_IT_EMAIL_UNSUBSCRIBE_URL', '#'),
        'update_preferences_url' => env('SEND_IT_EMAIL_UPDATE_PREFERENCES_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Per-channel configuration. Each enabled channel is registered with the
    | ChannelManager on boot.
    |
    */

    'channels' => [

        'mailchimp' => [
            'enabled' => env('SEND_IT_MAILCHIMP_ENABLED', true),

            // API key, e.g. "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21".
            'api_key' => env('SEND_IT_MAILCHIMP_API_KEY'),

            // Optional. Derived from the API key suffix (e.g. "us21") when null.
            'server_prefix' => env('SEND_IT_MAILCHIMP_SERVER_PREFIX'),

            // Default audience (list) id new campaigns are sent to. May be
            // overridden per-send from the action form.
            'audience_id' => env('SEND_IT_MAILCHIMP_AUDIENCE_ID'),

            'from_name' => env('SEND_IT_MAILCHIMP_FROM_NAME'),
            'reply_to' => env('SEND_IT_MAILCHIMP_REPLY_TO'),

            // When false (default), campaigns are created as drafts for review
            // inside Mailchimp. When true, they are sent immediately.
            'send_immediately' => env('SEND_IT_MAILCHIMP_SEND_IMMEDIATELY', false),

            // Entry field whose augmented HTML becomes the campaign body.
            'content_field' => 'content',
        ],

        // Sends the rendered entry through Laravel's configured mailer. Handy
        // for previewing/test-sending an entry before pushing a real campaign.
        'mailer' => [
            'enabled' => env('SEND_IT_MAILER_ENABLED', true),

            // Default recipient for test sends. Overridable per-send.
            'to' => env('SEND_IT_MAILER_TO'),

            'from_address' => env('SEND_IT_MAILER_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
            'from_name' => env('SEND_IT_MAILER_FROM_NAME', env('MAIL_FROM_NAME')),

            // Entry field whose augmented HTML becomes the email body.
            'content_field' => 'content',
        ],

    ],

];
