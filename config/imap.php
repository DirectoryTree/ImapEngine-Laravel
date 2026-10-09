<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailboxes
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the IMAP mailboxes your application connects to.
    | Each mailbox contains its connection settings such as host, port, and
    | credentials. You are free to add as many mailboxes as needed.
    |
    */

    'mailboxes' => [
        'default' => [
            'port' => env('IMAP_PORT', 993),
            'host' => env('IMAP_HOST'),
            'timeout' => env('IMAP_TIMEOUT', 30),
            'debug' => env('IMAP_DEBUG', false),
            'username' => env('IMAP_USERNAME'),
            'password' => env('IMAP_PASSWORD'),
            'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
            'validate_cert' => env('IMAP_VALIDATE_CERT', true),
            'authentication' => env('IMAP_AUTHENTICATION', 'login'),
            'proxy' => [
                'socket' => env('IMAP_PROXY_SOCKET'),
                'username' => env('IMAP_PROXY_USERNAME'),
                'password' => env('IMAP_PROXY_PASSWORD'),
                'request_fulluri' => env('IMAP_PROXY_REQUEST_FULLURI', false),
            ],
        ],
    ],
];
