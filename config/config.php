<?php

declare(strict_types=1);

/**
 * Assemble la configuration applicative à partir du fichier .env.
 *
 * Ne renvoie qu'un tableau : aucune logique, aucun effet de bord. Consommé par
 * la classe Config (accès « pointé » : app.debug, db.host, …).
 */

use Keepnew\Support\Env;

$root = dirname(__DIR__);
$env = Env::load($root . '/.env');

$get = static fn (string $key, ?string $default = null): ?string => $env[$key] ?? getenv($key) ?: $default;
$bool = static fn (string $key, bool $default = false): bool => in_array(
    strtolower((string) ($env[$key] ?? ($default ? 'true' : 'false'))),
    ['1', 'true', 'on', 'yes'],
    true,
);

return [
    'app' => [
        'env' => $get('APP_ENV', 'production'),
        'debug' => $bool('APP_DEBUG', false),
        'url' => $get('APP_URL', 'http://localhost'),
        'key' => $get('APP_KEY', ''),
        'secure_cookie' => $get('APP_ENV', 'production') !== 'local',
        'widget_prefix' => '/widget',
        'timezone_display' => $get('APP_TIMEZONE_DISPLAY', 'Europe/Brussels'),
    ],
    'db' => [
        'host' => $get('DB_HOST', '127.0.0.1'),
        'port' => $get('DB_PORT', '3306'),
        'name' => $get('DB_NAME', 'keepnew'),
        'user' => $get('DB_USER', 'root'),
        'password' => $get('DB_PASSWORD', ''),
        'charset' => $get('DB_CHARSET', 'utf8mb4'),
    ],
    'mail' => [
        'host' => $get('MAIL_HOST'),
        'port' => $get('MAIL_PORT', '587'),
        'user' => $get('MAIL_USER'),
        'password' => $get('MAIL_PASSWORD'),
        'encryption' => $get('MAIL_ENCRYPTION', 'tls'),
        'from_address' => $get('MAIL_FROM_ADDRESS', 'hello@keepnew.be'),
        'from_name' => $get('MAIL_FROM_NAME', 'Keepnew'),
    ],
    'geo' => [
        'provider' => $get('GEO_PROVIDER', 'ors'),
        'ors_api_key' => $get('ORS_API_KEY', ''),
        'google_maps_api_key' => $get('GOOGLE_MAPS_API_KEY', ''),
    ],
    'sms' => [
        'provider' => $get('SMS_PROVIDER', 'none'),
        'twilio_sid' => $get('TWILIO_SID', ''),
        'twilio_token' => $get('TWILIO_TOKEN', ''),
        'twilio_from' => $get('TWILIO_FROM', ''),
        'brevo_key' => $get('BREVO_API_KEY', ''),
        'brevo_sender' => $get('BREVO_SENDER', 'Keepnew'),
    ],
    'payment' => [
        'gateway' => $get('PAYMENT_GATEWAY', 'null'),
    ],
];
