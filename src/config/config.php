<?php
return [
    'ftp' => [
        'host' => 'your_sftp_host', // Example: 'sftp4.logiq.no'
        'port' => 22, // Example: 2222
        'username' => 'your_sftp_username', // Example: 'hartmnrdc'
        'password' => 'your_sftp_password', // Example: 'iWmshPu7d'
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