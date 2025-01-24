<?php
return [
    'ftp' => [
        'host' => 'your_sftp_host', // Example: 'sftp.example.com'
        'port' => 22, // Example: 22
        'username' => 'your_sftp_username', // Example: 'hartmann'
        'password' => 'your_sftp_password', // Example: 'pass123'
    ],
    'rackbeat' => [
        'api_url' => 'https://api.rackbeat.com', 
        'api_key' => 'your_rackbeat_api_key', // Example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...'
    ],
    'order' => [
        'remote_order_directory' => 'your_remote_order_directory', // Example: '/2hartmnrdc'
        'remote_confirmation_directory' => 'your_remote_confirmation_directory', // Example: '/2logiq'
    ],
];