<?php
/**
 * Konfigurasi aplikasi.
 *
 * Untuk mengubah koneksi database, salin file ini menjadi config.local.php
 * dan isi nilai di sana (file lokal tidak akan menimpa versi repository).
 */

declare(strict_types=1);

$root = __DIR__;

$config = [

    'app' => [
        'name'     => 'Monitor Suhu & Kelembapan Pengering',
        'timezone' => 'Asia/Jakarta',
        'debug'    => true,
        'version'  => '1.0.0',
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3307,          // XAMPP/MariaDB lokal
        'name'    => 'monitor_suhu_kelembapan',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'auth' => [
        'session_name'  => 'MSK_SESSID',
        'login_max_attempt' => 5,
        'lockout_seconds'   => 300,
    ],

    'device' => [
        // Kode perangkat default (bisa diubah lewat setting device_code)
        'default_code' => 'DRYER-01',
    ],

    'advisor' => [
        // Batas atas/bawah moisture untuk menyalin rekomendasi ETA
        'eta_min_hours' => 0.05,
        'eta_max_hours' => 240,
        // Umur data yang masih dianggap valid (menit)
        'stale_after_minutes' => 10,
    ],

    'cors' => [
        'enabled'      => true,
        'allow_origin' => '*',
    ],
];

if (is_file($root . '/config.local.php')) {
    /** @noinspection PhpIncludeInspection */
    $config = array_replace_recursive($config, require $root . '/config.local.php');
}

return $config;
