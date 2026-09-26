<?php
/**
 * ============================================================================
 *  PENERIMA MODBUS TCP  —  php backend/device/modbus_server.php
 * ============================================================================
 *  Untuk perangkat / gateway IoT Anda yang berbasis Modbus.
 *
 *  Cara kerja:
 *    - Script ini membuka socket TCP (default port 5020, karena 502 butuh root).
 *    - Modul IoT Anda bertindak sebagai Modbus TCP client: menulis data sensor
 *      ke register server, lalu membaca register perintah untuk aktuator.
 *
 *  PETA REGISTER (Holding Register, fungsi 4x):
 *      40001  SUHU (float32, 2 register)    °C
 *      40003  KELEMBAPAN (float32, 2 register)%
 *      40005  RH AMBIEN (float32, 2 register) %
 *      40007  SUHU AMBIEN (float32, 2 register) °C
 *      40009  STATUS HEATER (uint16)  0/1
 *      40010  DUTY HEATER (uint16)    0-100
 *      40011  STATUS KIPAS (uint16)   0/1
 *      40012  DUTY KIPAS (uint16)     0-100
 *      40013  BATAS SUHU MIN (float32, 2 register)
 *      40015  BATAS SUHU MAX (float32, 2 register)
 *      40017  TARGET KELEMBAPAN (float32, 2 register)
 *      40019  FLAG "DATA BARU" (uint16) = 1 setiap ada kiriman
 *
 *  CARA PAKAI MODUL ANDA:
 *    1) Buat koneksi Modbus TCP client ke IP server port 5020
 *    2) Tulis SUHU + KELEMBAPAN ke register 40001 dan 40003
 *    3) Baca register 40009 (heater) dan 40011 (kipas) untuk menyalakan aktuator
 *
 *  Contoh dengan Python (pymodbus):
 *    python backend/device/contoh_modbus.py --host 192.168.1.10 --port 5020
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line: php backend/device/modbus_server.php\n");
}

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Settings.php';
require __DIR__ . '/../src/Profile.php';
require __DIR__ . '/../src/Readings.php';
require __DIR__ . '/../src/Actuators.php';
require __DIR__ . '/../src/Alerts.php';
require __DIR__ . '/../src/DryingAdvisor.php';
require __DIR__ . '/../src/AutoControl.php';
require __DIR__ . '/../src/Weather.php';
require __DIR__ . '/../src/DeviceState.php';
require __DIR__ . '/../src/ModbusServer.php';

use App\Db;
use App\ModbusServer;
use App\Settings;

date_default_timezone_set((string) Db::app('timezone', 'Asia/Jakarta'));

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $arg, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}

$host    = $opt['host'] ?? '0.0.0.0';
$port    = (int) ($opt['port'] ?? 5020);
$device  = $opt['device'] ?? Settings::deviceCode();
$apiKey  = $opt['key'] ?? (getenv('MSK_API_KEY') ?: null);

Settings::flush();
Profile::flush();
$deviceCode = $apiKey === null ? $device : $device;

echo "========================================================\n";
echo " PENERIMA MODBUS TCP - Monitor Suhu & Kelembapan\n";
echo "========================================================\n";
echo "  Listen      : {$host}:{$port}\n";
echo "  Perangkat   : {$deviceCode}\n";
echo "  Register    : 40001 (suhu) .. 40018 (flag data baru)\n";
echo "  Tekan Ctrl+C untuk menghentikan.\n\n";

$server = new ModbusServer($host, $port, $deviceCode, static function (string $line): void {
    echo '[' . date('H:i:s') . '] ' . $line . "\n";
});
$server->run();
