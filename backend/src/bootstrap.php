<?php
/**
 * Bootstrap aplikasi: memuat konfigurasi dan seluruh class di src/.
 *
 * Dipakai oleh router web, installer, simulator, dan server Modbus
 * agar semua entry point memakai cara pemuatan yang sama.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/** @var array $appConfig */
$appConfig = require APP_ROOT . '/config.php';

/*
 * Zona waktu aplikasi.
 *
 * Wajib dipasang sebelum kode apa pun memakai date()/DateTime, karena default
 * PHP bisa saja Europe/Berlin sedangkan MariaDB berjalan di UTC+07. Selisih
 * beberapa jam membuat reading baru terlihat "lebih tua" dari data lama.
 */
date_default_timezone_set($appConfig['app']['timezone'] ?? 'Asia/Jakarta');

/*
 * Endpoint selalu menjawab JSON, jadi warning PHP tidak boleh bocor ke body
 * respons (dan membuat JSON tidak valid). Error tetap dicatat ke error_log.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(static function (string $class): void {
    // Seluruh class berada di namespace App.
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
