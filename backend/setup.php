<?php
/**
 * ============================================================================
 *  INSTALLER  —  php backend/setup.php
 * ============================================================================
 *  Membuat database, tabel, data awal (setting, profil material, akun admin,
 *  perangkat, API key) dan (opsional) mengisi data contoh 24 jam.
 *
 *  Cara pakai:
 *    php backend/setup.php                -> pasang / perbarui
 *    php backend/setup.php --demo         -> pasang + isi data simulasi
 *    php backend/setup.php --key          -> tampilkan / buat ulang API key
 *    php backend/setup.php --reset        -> HAPUS semua tabel lalu pasang ulang
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Hanya bisa dijalankan dari command line.\n");
}

require __DIR__ . '/src/Db.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/Profile.php';
require __DIR__ . '/src/Readings.php';
require __DIR__ . '/src/Actuators.php';
require __DIR__ . '/src/Alerts.php';
require __DIR__ . '/src/DryingAdvisor.php';
require __DIR__ . '/src/AutoControl.php';
require __DIR__ . '/src/Weather.php';

use App\Db;
use App\Settings;
use App\Profile;

date_default_timezone_set((string) Db::app('timezone', 'Asia/Jakarta'));

$args    = array_slice($argv, 1);
$seed    = require __DIR__ . '/database/seed.php';
$cfg     = Db::config();
$dbCfg   = $cfg['db'];

function line(string $text = ''): void
{
    echo $text, PHP_EOL;
}

function step(string $text): void
{
    line();
    line('== ' . $text . ' ' . str_repeat('=', max(0, 62 - mb_strlen($text))));
}

function ok(string $text): void
{
    line('  [OK]   ' . $text);
}

function info(string $text): void
{
    line('  [i]    ' . $text);
}

function warn(string $text): void
{
    line('  [!]    ' . $text);
}

function fail(string $text): never
{
    line('  [GAGAL] ' . $text);
    exit(1);
}

line();
line('  ' . str_pad((string) Db::app('name'), 60, '=', STR_PAD_BOTH));
line('  Installer v' . Db::app('version') . '  |  ' . date('d/m/Y H:i:s'));
line();

/* ------------------------------------------------------------------
 *  1. Koneksi & database
 * ------------------------------------------------------------------ */
step('Koneksi Database');

try {
    $server = Db::serverPdo();
    ok('Terhubung ke ' . $cfg['db']['host'] . ':' . $cfg['db']['port'] . ' (user: ' . $cfg['db']['user'] . ')');
} catch (Throwable $e) {
    fail('Tidak bisa konek MySQL/MariaDB: ' . $e->getMessage()
        . PHP_EOL . '        Pastikan service berjalan dan host/port/user di backend/config.php benar.');
}

$dbName = $dbCfg['name'];
if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
    fail('Nama database tidak valid.');
}

if (in_array('--reset', $args, true)) {
    warn('Mode --reset: semua data pada database `' . $dbName . '` akan dihapus.');
    $server->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    ok('Database lama dihapus.');
}

$server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
ok('Database `' . $dbName . '` siap.');

/* ------------------------------------------------------------------
 *  2. Tabel
 * ------------------------------------------------------------------ */
step('Struktur Tabel');

$sql = (string) file_get_contents(__DIR__ . '/database/schema.sql');
$sql = preg_replace('/^\s*(--[^\n]*)$/m', '', $sql);

$pdo = Db::pdo();
$statements = array_filter(array_map('trim', explode(';', (string) $sql)));
foreach ($statements as $stmt) {
    if ($stmt === '' || stripos($stmt, 'SET ') === 0) {
        continue;
    }
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        fail('Gagal membuat tabel: ' . $e->getMessage());
    }
}
ok(count($statements) . ' statement dijalankan (users, settings, profiles, devices, api_keys, readings, actuator_logs, alerts, drying_logs).');

/* ------------------------------------------------------------------
 *  3. Setting awal
 * ------------------------------------------------------------------ */
step('Data Awal (Setting)');

$upsert = $pdo->prepare(
    'INSERT INTO `settings` (setting_key, setting_value, value_type, label, unit, group_name, min_value, max_value)
     VALUES (:k, :v, :t, :l, :u, :g, :min, :max)
     ON DUPLICATE KEY UPDATE
        label = VALUES(label), unit = VALUES(unit), group_name = VALUES(group_name),
        min_value = VALUES(min_value), max_value = VALUES(max_value), value_type = VALUES(value_type)'
);

$count = 0;
foreach ($seed['settings'] as $s) {
    $upsert->execute([
        ':k' => $s['setting_key'], ':v' => $s['setting_value'], ':t' => $s['value_type'],
        ':l' => $s['label'], ':u' => $s['unit'], ':g' => $s['group_name'],
        ':min' => $s['min_value'] ?? null, ':max' => $s['max_value'] ?? null,
    ]);
    $count++;
}
ok($count . ' setting dipasang (label & batas minimum/maksimum diperbarui, nilai lama dipertahankan).');

/* ------------------------------------------------------------------
 *  4. Profil material
 * ------------------------------------------------------------------ */
step('Profil Material');

$profStmt = $pdo->prepare(
    'INSERT INTO `material_profiles`
       (code, name, description, initial_moisture, target_moisture, stop_moisture,
        safe_temp_min, optimal_temp, safe_temp_max, limit_temp_max, fan_min_duty, base_rate, thickness_default, is_default)
     VALUES (:code,:name,:desc,:init,:target,:stop,:tmin,:topt,:tmax,:tlim,:fan,:rate,:thick,:def)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name), description = VALUES(description)'
);
foreach ($seed['profiles'] as $p) {
    $profStmt->execute([
        ':code' => $p['code'], ':name' => $p['name'], ':desc' => $p['description'],
        ':init' => $p['initial_moisture'], ':target' => $p['target_moisture'], ':stop' => $p['stop_moisture'],
        ':tmin' => $p['safe_temp_min'], ':topt' => $p['optimal_temp'], ':tmax' => $p['safe_temp_max'],
        ':tlim' => $p['limit_temp_max'], ':fan' => $p['fan_min_duty'], ':rate' => $p['base_rate'],
        ':thick' => $p['thickness_default'], ':def' => $p['is_default'],
    ]);
}
ok(count($seed['profiles']) . ' profil material tersedia: ' . implode(', ', array_column($seed['profiles'], 'name')) . '.');

/* ------------------------------------------------------------------
 *  5. Akun admin
 * ------------------------------------------------------------------ */
step('Akun Dashboard');

$userStmt = $pdo->prepare('SELECT id, password_hash FROM `users` WHERE username = ?');
$userStmt->execute([$seed['admin']['username']]);
$user = $userStmt->fetch();

if (!$user) {
    $ins = $pdo->prepare('INSERT INTO `users` (username, password_hash, name, role, is_active) VALUES (?,?,?,"admin",1)');
    $ins->execute([$seed['admin']['username'], password_hash($seed['admin']['password'], PASSWORD_DEFAULT), $seed['admin']['name']]);
    ok('Akun dibuat: ' . $seed['admin']['username'] . ' / ' . $seed['admin']['password']);
} else {
    ok('Akun ' . $seed['admin']['username'] . ' sudah ada (password tidak diubah).');
}

/* ------------------------------------------------------------------
 *  6. Perangkat + API key
 * ------------------------------------------------------------------ */
step('Perangkat & API Key');

Settings::flush();
Profile::flush();
$deviceCode = Settings::deviceCode();

$dev = $pdo->prepare('SELECT id FROM `devices` WHERE device_code = ?');
$dev->execute([$deviceCode]);
if (!$dev->fetchColumn()) {
    $pdo->prepare('INSERT INTO `devices` (device_code, name, location, profile_code, is_active) VALUES (?,?,?,?,1)')
        ->execute([$deviceCode, Settings::str('device_name'), Settings::str('location'), Settings::str('material_profile')]);
    ok('Perangkat ' . $deviceCode . ' terdaftar.');
} else {
    ok('Perangkat ' . $deviceCode . ' sudah terdaftar.');
}

$keyStmt = $pdo->prepare('SELECT id FROM `api_keys` WHERE device_code = ? AND is_active = 1 LIMIT 1');
$keyStmt->execute([$deviceCode]);
$existing = $keyStmt->fetchColumn();

if (in_array('--key', $args, true) || in_array('--rotate-key', $args, true)) {
    if ($existing) {
        $pdo->prepare('DELETE FROM `api_keys` WHERE id = ?')->execute([$existing]);
    }
    $existing = null;
}

$apiKey = null;
if ($existing) {
    info('API key sudah ada (disimpan sebagai hash, tidak bisa ditampilkan lagi).');
    info('Gunakan "php backend/setup.php --key" untuk membuat kunci baru.');
} else {
    $apiKey = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO `api_keys` (device_code, label, key_hash, scopes, is_active) VALUES (?,?,?,"sensor:write,control:read",1)')
        ->execute([$deviceCode, 'Modbus/IoT Gateway', hash('sha256', $apiKey)]);
    ok('API key baru dibuat.');
}

/* ------------------------------------------------------------------
 *  7. Data demo
 * ------------------------------------------------------------------ */
if (in_array('--demo', $args, true)) {
    step('Data Contoh (Simulasi 24 Jam)');
    require __DIR__ . '/device/seed_demo.php';
    $inserted = seed_demo_readings($deviceCode, 24 * 60);
    ok($inserted . ' baris data sensor simulasi 24 jam dimasukkan.');
}

/* ------------------------------------------------------------------
 *  8. Ringkasan
 * ------------------------------------------------------------------ */
step('Ringkasan');
Settings::flush();
Profile::flush();

$base = 'http://localhost/monitor-suhu-kelembapan';
line('  Dashboard    : ' . $base . '/');
line('  Login        : ' . $seed['admin']['username'] . ' / ' . $seed['admin']['password'] . ' (ubah setelah login)');
line('  Database     : ' . $dbCfg['host'] . ':' . $dbCfg['port'] . ' / ' . $dbName);
line('  Perangkat    : ' . $deviceCode);
line('  Material     : ' . Profile::get()['name']);
if ($apiKey !== null) {
    line();
    line('  >>> API KEY (simpan sekarang, tidak ditampilkan lagi):');
    line('      ' . $apiKey);
    line();
    line('  Contoh kiriman sensor:');
    line('      curl -X POST ' . $base . '/api/sensor/ingest \\');
    line('        -H "X-Api-Key: ' . $apiKey . '" \\');
    line('        -H "Content-Type: application/json" \\');
    line('        -d "{\"temp_c\":38.5,\"moisture_pct\":22.4,\"fan_on\":true,\"fan_duty\":80}"');
}
line();
line('  Selesai. Jalankan `npm run dev` di folder frontend untuk mode pengembangan,');
line('  atau `npm run build` lalu buka lewat XAMPP/Apache.');
line();

/* ------------------------------------------------------------------
 *  Sanity check
 * ------------------------------------------------------------------ */
try {
    Db::pdo()->query('SELECT COUNT(*) FROM `readings`')->fetchColumn();
    ok('Uji koneksi ulang: berhasil.');
} catch (Throwable $e) {
    warn('Uji koneksi gagal: ' . $e->getMessage());
}

exit(0);
