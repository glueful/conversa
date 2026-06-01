<?php

return [
    'default' => [
        'sms'      => env('CONVERSA_SMS_DRIVER', 'log'),
        'whatsapp' => env('CONVERSA_WHATSAPP_DRIVER', 'log'),
    ],
    'drivers' => [
        'twilio' => [
            'enabled'       => (bool) env('CONVERSA_TWILIO_ENABLED', true),
            'sid'           => env('CONVERSA_TWILIO_SID'),
            'token'         => env('CONVERSA_TWILIO_TOKEN'),
            'sms_from'      => env('CONVERSA_TWILIO_SMS_FROM'),
            'whatsapp_from' => env('CONVERSA_TWILIO_WHATSAPP_FROM'),
        ],
        'whatsapp_cloud' => [
            'enabled'      => (bool) env('CONVERSA_WHATSAPP_ENABLED', true),
            'phone_id'     => env('CONVERSA_WHATSAPP_PHONE_ID'),
            'token'        => env('CONVERSA_WHATSAPP_TOKEN'),
            'verify_token' => env('CONVERSA_WHATSAPP_VERIFY_TOKEN'),
            'app_secret'   => env('CONVERSA_WHATSAPP_APP_SECRET'),
        ],
        'log' => ['enabled' => true],
    ],
    'templates' => [
        // 'order_shipped' => [
        //     'whatsapp_cloud' => ['name' => 'order_shipped', 'language' => 'en_US'],
        //     'twilio'         => ['content_sid' => 'HXxxxxxxxx'],
        // ],
    ],
    // Public base URL of this app (e.g. https://api.example.com), used to rebuild the
    // external callback URL Twilio signed when running behind a proxy/load balancer.
    'webhook_base_url' => env('CONVERSA_WEBHOOK_BASE_URL'),
    'features' => [
        'store_body'               => (bool) env('CONVERSA_STORE_BODY', true),
        'redact_provider_response' => (bool) env('CONVERSA_REDACT_PROVIDER_RESPONSE', true),
        'max_retries'              => (int) env('CONVERSA_MAX_RETRIES', 3),
        'log_messages'             => (bool) env('CONVERSA_LOG_MESSAGES', true),
    ],
];
