<?php
/**
 * CONTOH konfigurasi untuk server produksi.
 *
 * CARA PAKAI:
 *   1. Salin file ini  ->  backend/config.local.php
 *      (di Windows:  copy backend\config.example.php backend\config.local.php )
 *   2. Isi nilai di bawah sesuai server Anda
 *   3. JANGAN PERNAH commit config.local.php ke GitHub
 *      (sudah otomatis diabaikan oleh .gitignore)
 *
 * File ini hanya MENIMPA nilai yang ada di config.php, jadi Anda cukup
 * mengisi bagian yang berubah saja.
 */

declare(strict_types=1);

$config = [

    'app' => [
        // WAJIB false di produksi. true = menampilkan detail error ke pengunjung.
        'debug' => false,
        'timezone' => 'Asia/Jakarta',
    ],

    'db' => [
        // Shared hosting biasanya host-nya 'localhost', bukan 127.0.0.1
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'nama_database_anda',
        'user' => 'user_database_anda',
        // Ambil dari cPanel -> MySQL Databases -> "Change Password"
        'pass' => 'password-database-anda',
        'charset' => 'utf8mb4',
    ],

    'device' => [
        'default_code' => 'DRYER-01',
    ],

    'cors' => [
        // Kalau hanya diakses lewat domain sendiri, ubah ke domain Anda
        // atau set false.
        'enabled' => true,
        'allow_origin' => 'https://domain-anda.com',
    ],
];

return $config;
