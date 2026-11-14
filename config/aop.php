<?php

return [
    'version' => env('AOP_VERSION', '1.0.0'),
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AOP_TRUSTED_PROXIES', '')),
    ))),
    'enforce_https' => filter_var(env('AOP_ENFORCE_HTTPS', false), FILTER_VALIDATE_BOOL),
    'allow_self_registration' => env('AOP_ALLOW_SELF_REGISTRATION', false),
];
