<?php
declare(strict_types=1);

/**
 * iConnect_Menus — upload these files to CentOS /var/www/html/api/lcr/
 * Public: https://sakatamalaybalay.com/api/lcr/ccrogw.php
 * Target: https://192.168.108.89:4433/elcr/eMenu/
 *
 * backend_base_url includes /eMenu. ccrogw.php uses the parent folder (/elcr)
 * so the other apps next to eMenu stay on the same host.
 */
return [
    'backend_base_url' => 'https://192.168.108.89:4433/elcr/eMenu',

    'menu_path' => '/eMenu/index.php',
    'menu_json_path' => '/eMenu/apps.json.php',
    'menu_mode' => 'proxy',
    'url_mode' => 'pathinfo',
    'gateway_token' => '',

    'transparent_urls' => false,

    'opaque_urls' => true,
    'url_key' => 'ccro-gw-7f3a9c2e1b8d4f6a0e5c9b2d7a1f4e8c',

    'shortcuts' => [
        'v' => '/evitalNewReact/public/index.php',
        'e' => '/eVital/index.php',
        'm' => '/eMenu/index.php',
    ],

    'connect_timeout' => 8,
    'timeout' => 60,
    'max_body_bytes' => 52428800,

    'portal' => [
        'line1' => '',
        'line2' => 'SolArt Tech Solutions',
        'line3' => 'Application Gateway',
        'timezone' => 'Asia/Manila',
    ],
];
