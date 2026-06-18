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
