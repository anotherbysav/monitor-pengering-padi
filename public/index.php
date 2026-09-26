<?php
/**
 * =====================================================================
 *  ROUTER  -  Monitor Suhu & Kelembapan Pengering Padi
 * =====================================================================
 *  Satu file untuk API JSON sekaligus penyajian frontend Vue.
 *
 *  Endpoint (prefix /api):
 *
 *  Dashboard  GET  /api/health                        publik
 *
 *  Sensor     POST /api/sensor/ingest                X-Api-Key   (kirim data)
 *             GET  /api/sensor/poll                  X-Api-Key   (ambil perintah)
 *
 *  State      GET  /api/state
 *             GET  /api/pulse
 *
 *  Rekomendasi GET /api/recommendation
 *             GET  /api/recommendation/history
 *
 *  Riwayat    GET  /api/readings/history
 *             GET  /api/readings/stats
 *             GET  /api/readings/export              (CSV)
 *             GET  /api/logs/actuators
 *
 *  Kontrol    POST /api/controls
 *             POST /api/controls/all-off
 *
 *  Alert      GET  /api/alerts
 *             POST /api/alerts/acknowledge
 *             POST /api/alerts/clear
 *
 *  Pengaturan GET  /api/settings
 *             GET  /api/settings/metadata
 *             PUT  /api/settings
 *
 *  Perangkat  GET  /api/devices
 *             POST /api/devices/api-key
 *             DELETE /api/devices/api-key
 *
 *  Utilitas   GET  /api/weather
 *             POST /api/maintenance/purge
 *
 *  Dashboard tidak memakai login. Endpoint perangkat (/api/sensor/*)
 *  tetap dilindungi API key karena diakses oleh mikrokontroler.
 *
 *  Jalankan development:
 *      php -S 127.0.0.1:8000 -t public public/index.php
 * =====================================================================
 */

declare(strict_types=1);

use App\Actuators;
use App\Alerts;
use App\Auth;
use App\AutoControl;
use App\Db;
use App\DeviceState;
use App\DryingAdvisor;
use App\Http;
use App\Profile;
use App\Readings;
use App\Settings;
use App\Weather;

require_once __DIR__ . '/../backend/src/bootstrap.php';

/* =====================================================================
 *  NORMALISASI PAYLOAD
 *  Class internal memakai nama ringkas (temp, moist, ...). Untuk API
 *  publik dan frontend, nama dibuat eksplisit (temp_c, moisture_pct, ...)
 *  supaya mudah dipahami integrator perangkat.
 * ================================================================== */

/** Baris riwayat -> bentuk API publik. */
function shapeReading(array $r): array
{
    $fanDuty   = isset($r['fan_duty']) ? (float) $r['fan_duty'] : null;
    $heaterDuty = isset($r['heater_duty']) ? (float) $r['heater_duty'] : null;

    return [
        't'              => $r['t'] ?? null,
        'recorded_at'    => $r['t'] ?? null,
        'temp_c'         => $r['temp_c'] ?? $r['temp'] ?? null,
        'temp'           => $r['temp'] ?? $r['temp_c'] ?? null,
        'moisture_pct'   => $r['moisture_pct'] ?? $r['moisture'] ?? $r['moist'] ?? null,
        'moisture'       => $r['moisture'] ?? $r['moist'] ?? $r['moisture_pct'] ?? null,
        'temp_min_c'     => $r['temp_min_c'] ?? $r['lo'] ?? null,
        'temp_max_c'     => $r['temp_max_c'] ?? $r['hi'] ?? null,
        'ambient_rh_pct'  => $r['ambient_rh_pct'] ?? $r['ambient_rh'] ?? $r['rh'] ?? null,
        'ambient_temp_c'  => $r['ambient_temp_c'] ?? $r['ambient_temp'] ?? $r['amb'] ?? null,
        'heater_on'      => (bool) ($r['heater_on'] ?? $r['heater'] ?? false),
        'heater_duty'    => $heaterDuty ?? ($r['heater'] ?? false ? ($r['heater_duty'] ?? null) : 0),
        'fan_on'         => (bool) ($r['fan_on'] ?? $r['fan'] ?? false),
        'fan_duty'       => $fanDuty ?? ($r['fan'] ?? false ? ($r['fan_duty'] ?? null) : 0),
        'source'         => $r['source'] ?? null,
    ];
}

/** History -> bentuk API publik. */
function shapeHistory(array $history): array
{
    $rows = array_map('shapeReading', $history['rows'] ?? []);

    return [
        'rows' => $rows,
        'step' => (int) ($history['step_seconds'] ?? 0),
        'step_seconds' => (int) ($history['step_seconds'] ?? 0),
        'total' => (int) ($history['total'] ?? count($rows)),
    ];
}

/** Bacaan terakhir (snapshot/pulse) -> bentuk API publik. */
function shapeCurrentReading(?array $r, array $trend = [], array $controls = []): ?array
{
    if ($r === null) {
        return null;
    }

    $tempPerMin  = (float) ($trend['temp_per_min'] ?? 0);
    $moistPerHour = (float) ($trend['moist_per_hour'] ?? 0);

    $trendTemp = [
        'delta_10m'    => round($tempPerMin * 10, 2),
        'per_min'      => round($tempPerMin, 3),
        'span_minutes' => $trend['span_minutes'] ?? null,
    ];
    $trendMoist = [
        'delta_10m'    => round($moistPerHour * 10 / 60, 2),
        'per_hour'     => round($moistPerHour, 3),
        'span_minutes' => $trend['span_minutes'] ?? null,
    ];

    return array_merge(shapeReading($r), [
        'age_seconds' => $r['age_seconds'] ?? 0,
        'fresh'       => (bool) ($r['fresh'] ?? true),
        'trend_temp'  => $trendTemp,
        'trend_moist' => $trendMoist,
        'trend'       => [
            'temp_per_min'   => $trendTemp['per_min'],
            'moist_per_hour' => $trendMoist['per_hour'],
            'span_minutes'   => $trend['span_minutes'] ?? null,
        ],
        // Snapshot tidak memuat status aktuator; ambil dari state aktual.
        'heater_on'   => (bool) ($controls['heater']['on'] ?? false),
        'heater_duty' => (int) ($controls['heater']['duty'] ?? 0),
        'fan_on'      => (bool) ($controls['fan']['on'] ?? false),
        'fan_duty'    => (int) ($controls['fan']['duty'] ?? 0),
    ]);
}

const FACTOR_TONE = [
    'Suhu'            => 'good',
    'Aliran udara'    => 'good',
    'Gradien lembap'  => 'good',
    'Ketebalan lapis' => 'warn',
    'Cuaca / RH'      => 'good',
];

/** Hasil DryingAdvisor -> bentuk API publik / frontend. */
function shapeAdvisor(array $a): array
{
    $data     = $a['data'] ?? [];
    $m        = $data['metrics'] ?? [];   // nilai numerik mentah
    $etaHours = $a['estimated_hours'];
    $quality  = $data['quality'] ?? ['risk' => 'rendah', 'score' => 0, 'notes' => []];
    $best     = $data['best_time'] ?? [];
    $summary  = $a['summary'] ?? ['items' => []];

    /* --- faktor: dari list menjadi object per faktor --- */
    $factors = [];
    foreach ($a['factors'] ?? [] as $f) {
        $name  = (string) ($f['name'] ?? '');
        $value = (float) ($f['value'] ?? 0);
        $max   = (float) ($f['max'] ?? 1) ?: 1.0;
        $factors[] = [
            'name'   => $name,
            'label'  => $name,
            'value'  => round($value, 3),
            'pct'    => (int) round(min(1.0, $value / $max) * 100),
            'detail' => (string) ($f['note'] ?? ''),
            'tone'   => FACTOR_TONE[$name] ?? 'good',
        ];
    }

    /* --- checklist: dari conditions (state ok/warn/bad) --- */
    $checklist = [];
    foreach ($a['conditions'] ?? [] as $c) {
        $checklist[] = [
            'key'   => $c['key'] ?? null,
            'label' => $c['label'] ?? null,
            'ok'    => ($c['state'] ?? '') === 'ok',
            'value' => $c['state'] ?? null,
            'detail'=> $c['detail'] ?? null,
        ];
    }

    /* --- kondisi ringkas (label + nilai) --- */
    $rh       = (float) ($m['ambient_rh'] ?? 0);
    $moistNow = (float) ($m['moisture'] ?? 0);
    $mStop    = (float) ($m['moisture_stop'] ?? 0);

    $conditions = [
        ['label' => 'Suhu ruang',          'value' => self_num($m['temp'] ?? null) . " \u{00B0}C", 'tone' => 'good'],
        ['label' => 'Kelembapan saat ini', 'value' => self_num($moistNow) . ' %',               'tone' => 'good'],
        ['label' => 'RH ambient',          'value' => self_num($rh) . ' %',                     'tone' => $rh > 80 ? 'warn' : 'good'],
        ['label' => 'Kipas',               'value' => ($data['fan'] ?? false) ? 'Nyala ' . (int) ($data['fan_duty'] ?? 0) . '%' : 'Mati', 'tone' => ($data['fan'] ?? false) ? 'good' : 'warn'],
        ['label' => 'Heater',              'value' => ($data['heater'] ?? false) ? 'Nyala ' . (int) ($data['heater_duty'] ?? 0) . '%' : 'Mati', 'tone' => 'good'],
        ['label' => 'Sisa kelembapan',    'value' => self_num(max(0.0, $moistNow - $mStop)) . ' %', 'tone' => 'good'],
    ];

    return [
        'status'      => $a['status'] ?? null,
        'label'       => $a['label'] ?? null,
        'tone'        => $a['tone'] ?? 'slate',
        'icon'        => $a['icon'] ?? $summary['icon'] ?? 'clock',
        'score'       => (int) ($a['score'] ?? 0),
        'ready_to_dry'=> (bool) ($a['ready_to_dry'] ?? false),
        'drying_rate' => $a['drying_rate'] === null ? null : (float) $a['drying_rate'],
        'target_moisture_pct' => (float) ($m['moisture_stop'] ?? 0),
        'eta' => [
            'seconds' => $etaHours === null ? null : (int) round($etaHours * 3600),
            'hours'   => $etaHours,
            'text'    => $a['eta_text'] ?? null,
            'at'      => $a['eta_at'] ?? null,
        ],
        'eta_text'   => $a['eta_text'] ?? null,
        'eta_at'     => $a['eta_at'] ?? null,
        'progress'   => [
            'pct'  => (float) ($a['progress_pct'] ?? 0),
            'from' => (float) ($m['moisture_initial'] ?? 0),
            'to'   => (float) ($m['moisture_stop'] ?? 0),
        ],
        'progress_pct' => (float) ($a['progress_pct'] ?? 0),
        'observed_rate'=> $data['observed_rate'] ?? null,
        'conditions' => $a['conditions'] ?? [],
        'data' => [
            'temp'            => $m['temp'] ?? null,
            'moisture'        => $m['moisture'] ?? null,
            'ambient_temp'    => $m['ambient_temp'] ?? null,
            'ambient_rh'      => $m['ambient_rh'] ?? null,
            'heater'          => (bool) ($data['heater'] ?? false),
            'heater_duty'     => (int) ($data['heater_duty'] ?? 0),
            'fan'             => (bool) ($data['fan'] ?? false),
            'fan_duty'        => (int) ($data['fan_duty'] ?? 0),
            'mode'            => $data['mode'] ?? 'auto',
            'layer_thickness' => $m['layer_thickness'] ?? null,
            'moisture_remaining' => $m['moisture_remaining'] ?? null,
            'age_seconds'     => $data['age_seconds'] ?? null,

            'temp_min'     => $m['temp_min'] ?? null,
            'temp_max'     => $m['temp_max'] ?? null,
            'temp_optimal' => $m['temp_optimal'] ?? null,
            'temp_limit'   => $m['temp_limit'] ?? null,
            'moisture_target' => $m['moisture_target'] ?? null,
            'moisture_stop'   => $m['moisture_stop'] ?? null,
            'moisture_initial'=> $m['moisture_initial'] ?? null,

            'conditions' => $conditions,
            'checklist'  => $checklist,
            'factors'    => $factors,
            'advice'     => $summary['items'] ?? [],
            'risks'      => $quality['notes'] ?? [],
            'risk_level' => $quality['risk'] ?? 'rendah',
            'quality_score' => (int) ($quality['score'] ?? 0),
            'best_time'  => [
                'label'  => $best['label'] ?? null,
                'detail' => $best['reason'] ?? null,
                'icon'   => $best['icon'] ?? 'clock',
                'tone'   => $best['tone'] ?? $a['tone'] ?? 'slate',
                'window' => $best['label'] ?? null,
                'verdict'=> $best['verdict'] ?? null,
            ],
            'material' => [
                'code'   => $data['profile']['code'] ?? Profile::active()['code'] ?? null,
                'name'   => $data['profile']['name'] ?? Profile::active()['name'] ?? null,
                'notes'  => Profile::active()['description'] ?? null,
                'target_moisture_pct' => $m['moisture_target'] ?? null,
                'initial_moisture'     => $m['moisture_initial'] ?? null,
            ],
        ],
    ];
}

function self_num(mixed $v, int $decimals = 1): string
{
    if ($v === null || $v === '') {
        return '--';
    }
    return number_format((float) $v, $decimals, ',', '.');
}

/* =====================================================================
 *  BOOT
 * ================================================================== */

$method = Http::method();
$path   = Http::path();

/* CORS / preflight */
Http::boot();
if ($method === 'OPTIONS') {
    Http::preflight();
}

/* =====================================================================
 *  FRONTEND (file statis + fallback SPA)
 * ================================================================== */
if (!str_starts_with($path, '/api')) {
    $root = __DIR__;

    /*
     * Resolusi file statis memakai URI mentah, bukan Http::path().
     *
     * Http::path() menghitung base dari dirname(SCRIPT_NAME) untuk
     * mendukung deploy di subdirektori. Namun PHP built-in server
     * menyetel SCRIPT_NAME ke path yang diminta bila file tersebut ADA
     * (contoh: /assets/app.css), sehingga base jadi "/assets" dan path
     * terpotong menjadi "/app.css". Akibatnya asset hasil build tidak
     * pernah ditemukan dan request jatuh ke fallback SPA.
     *
     * Karena itu coba URI mentah lebih dulu, lalu path dari Http::path()
     * sebagai cadangan untuk deploy subdirektori.
     */
    $uriPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    $file = false;
    foreach ([$uriPath, $path] as $candidate) {
        if (!is_string($candidate) || $candidate === '' || str_contains($candidate, "\0")) {
            continue;
        }
        $resolved = realpath($root . DIRECTORY_SEPARATOR . ltrim($candidate, '/'));
        if ($resolved !== false
            && is_file($resolved)
            && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
        ) {
            $file = $resolved;
            break;
        }
    }

    // file statis yang ada
    if ($file !== false) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            'html' => 'text/html; charset=utf-8',
            'js'   => 'text/javascript; charset=utf-8',
            'mjs'  => 'text/javascript; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
            'map'  => 'application/json; charset=utf-8',
        ];
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
        }
        if (str_starts_with($file, $root . DIRECTORY_SEPARATOR . 'assets')) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: no-cache');
        }
        readfile($file);
        exit;
    }

    // fallback ke index.html (SPA routing)
    $index = $root . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile($index);
        exit;
    }

    Http::fail(
        'Frontend belum di-build. Jalankan: cd frontend && npm install && npm run build',
        503,
        'frontend_missing'
    );
    exit;
}

/* =====================================================================
 *  API
 * ================================================================== */

$device = static fn(): string => Settings::deviceCode();

Http::handle(static function () use ($method, $path, $device): void {
    /* ==================== PUBLIK ==================== */

    if ($path === '/api/health') {
        $dbOk = true;
        $count = 0;
        try {
            $stmt = Db::pdo()->query('SELECT COUNT(*) FROM `readings`');
            $count = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $dbOk = false;
        }

        Http::ok([
            'app'      => 'Monitor Suhu & Kelembapan Pengering',
            'version'  => Db::app('version', '1.0.0'),
            'php'      => PHP_VERSION,
            'database' => $dbOk ? 'connected' : 'error',
            'readings' => $count,
            'device'   => $device(),
            'time'     => date('c'),
        ], 'Server aktif.');
        return;
    }

    /* ==================== SENSOR (API key, tanpa session) ==================== */

    if ($path === '/api/sensor/ingest' && $method === 'POST') {
        Auth::requireDevice();
        $payload = Http::input();

        $hasTemp   = array_key_exists('temp_c', $payload) || array_key_exists('temp', $payload);
        $hasMoist  = array_key_exists('moisture_pct', $payload) || array_key_exists('moisture', $payload);
        if (!$hasTemp || !$hasMoist) {
            Http::fail('Data minimal harus memuat temp_c dan moisture_pct.', 422, 'incomplete_reading');
            return;
        }

        $reading = [
            'temp_c'          => Http::float('temp_c', Http::float('temp', 0.0)),
            'moisture_pct'    => Http::float('moisture_pct', Http::float('moisture', 0.0)),
            'ambient_temp_c'  => Http::input()['ambient_temp_c'] ?? null,
            'ambient_rh_pct'  => Http::input()['ambient_rh_pct'] ?? null,
            'temp_min_c'      => Http::input()['temp_min_c'] ?? null,
            'temp_max_c'      => Http::input()['temp_max_c'] ?? null,
            'heater_on'       => Http::bool('heater_on', false),
            'heater_duty'     => Http::int('heater_duty', 0),
            'fan_on'          => Http::bool('fan_on', false),
            'fan_duty'        => Http::int('fan_duty', 0),
            'source'          => 'device',
        ];

        $readingId  = Readings::insert($reading, $device());
        Auth::ensureDevice($device(), Http::str('firmware') ?: null, $_SERVER['REMOTE_ADDR'] ?? null);

        // kontrol otomatis + peringatan berdasarkan data terbaru
        $auto    = AutoControl::tick($device());
        $alerts  = AutoControl::watchAlerts($device());

        $latest  = Readings::latest($device());
        $trend   = Readings::trend($device(), 30);
        $advisor = DryingAdvisor::evaluate($latest, Profile::active(), Actuators::state($device()), [
            'weather' => Weather::current(),
            'trend'   => $trend,
        ]);
        if (Settings::bool('log_interval')) {
            DryingAdvisor::log($device(), $advisor, $latest);
        }

        Http::ok([
            'reading_id' => $readingId,
            'advisor'    => shapeAdvisor($advisor),
            'auto'       => $auto,
            'command'    => Actuators::target($device()),
            'controls'   => Actuators::state($device()),
            'alerts'     => Alerts::active($device()),
        ], 'Data sensor diterima.');
        return;
    }

    if ($path === '/api/sensor/poll') {
        Auth::requireDevice();
        $auto    = AutoControl::tick($device());
        $controls = Actuators::state($device());

        Http::ok([
            'device_code' => $device(),
            'command'     => Actuators::target($device()),
            'controls'    => $controls,
            'thresholds'  => DeviceState::thresholds(),
            'mode'        => Settings::bool('auto_mode') ? 'auto' : 'manual',
            'interval_ms' => Settings::int('poll_interval_ms'),
            'server_time' => date('c'),
            'auto'        => $auto,
        ], 'Perangkat siap.');
        return;
    }

    /* ==================== DASHBOARD (tanpa login) ==================== */

    if ($path === '/api/state') {
        $snapshot = DeviceState::snapshot($device(), [
            'history'         => Http::query('history') !== '0',
            'history_minutes' => Http::int('minutes', 180),
            'limit'           => Http::int('limit', 700),
        ]);

        $reading = shapeCurrentReading($snapshot['reading'], $snapshot['trend'] ?? [], $snapshot['controls'] ?? []);
        $advisor = shapeAdvisor($snapshot['advisor']);

        Http::ok([
            'device'     => $snapshot['device'],
            'reading'    => $reading,
            'trend'      => $snapshot['trend'],
            'weather'    => $snapshot['weather'],
            'controls'   => $snapshot['controls'],
            'target'     => $snapshot['target'],
            'advisor'    => $advisor,
            'alerts'     => $snapshot['alerts'],
            'thresholds' => $snapshot['thresholds'],
            'history'    => shapeHistory($snapshot['history'] ?? []),
            'stats'      => Readings::stats($device(), max(15, (int) ($snapshot['history_minutes'] ?? 180))),
        ]);
        return;
    }

    if ($path === '/api/pulse') {
        $pulse = DeviceState::pulse($device());
        $pulse['reading'] = shapeCurrentReading($pulse['reading'] ?? null, $pulse['trend'] ?? [], $pulse['controls'] ?? []);
        $pulse['advisor'] = shapeAdvisor($pulse['advisor']);
        // Kirim juga thresholds: kartu "Batas Suhu Pengering" memakai
        // nilai ini, dan tanpa ini nilainya tetap basi setelah polling.
        $pulse['thresholds'] = DeviceState::thresholds();
        Http::ok($pulse);
        return;
    }

    /* ==================== REKOMENDASI ==================== */

    if ($path === '/api/recommendation') {
        $latest  = Readings::latest($device());
        $advisor = DryingAdvisor::evaluate($latest, Profile::active(), Actuators::state($device()), [
            'weather' => Weather::current(),
            'trend'   => Readings::trend($device(), 30),
        ]);

        Http::ok([
            'advisor' => shapeAdvisor($advisor),
            'weather' => Weather::current(),
            'reading' => shapeCurrentReading($latest, Readings::trend($device(), 30), $controls ?? []),
        ]);
        return;
    }

    if ($path === '/api/recommendation/history') {
        Http::ok(['items' => DryingAdvisor::history($device(), Http::int('limit', 40))]);
        return;
    }

    /* ==================== RIWAYAT ==================== */

    if ($path === '/api/readings/history') {
        $minutes = max(1, Http::int('minutes', 180));
        $limit   = max(10, min(2000, Http::int('limit', 700)));
        $history = Readings::history($device(), $minutes, $limit, Http::str('interval', 'auto'));
        Http::ok(shapeHistory($history));
        return;
    }

    if ($path === '/api/readings/stats') {
        Http::ok(Readings::stats($device(), max(5, Http::int('minutes', 60))));
        return;
    }

    if ($path === '/api/readings/export') {
        $minutes = max(1, Http::int('minutes', 1440));
        $history = Readings::history($device(), $minutes, 5000, 'raw');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="riwayat-pengering-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
        fputcsv($out, ['Waktu', 'Suhu (C)', 'Kelembapan (%)', 'Suhu min (C)', 'Suhu max (C)', 'RH ambient (%)', 'Heater', 'Kipas'], ';');
        foreach ($history['rows'] as $row) {
            $r = shapeReading($row);
            fputcsv($out, [
                (string) $r['t'],
                $r['temp_c'],
                $r['moisture_pct'],
                $r['temp_min_c'],
                $r['temp_max_c'],
                $r['ambient_rh_pct'],
                $r['heater_on'] ? 'ON' : 'OFF',
                $r['fan_on'] ? 'ON' : 'OFF',
            ], ';');
        }
        fclose($out);
        exit;
    }

    if ($path === '/api/logs/actuators') {
        Http::ok(['items' => Actuators::history($device(), max(1, Http::int('limit', 50)))]);
        return;
    }

    /* ==================== KONTROL ==================== */

    if ($path === '/api/controls' && $method === 'POST') {
        $actuator = Http::str('actuator');
        $on       = Http::bool('on', false);
        $duty     = Http::input()['duty'] ?? null;

        if (!in_array($actuator, ['heater', 'fan'], true)) {
            Http::fail('Aktuator harus "heater" atau "fan".', 422, 'invalid_actuator');
            return;
        }

        try {
            $result = Actuators::set($device(), $actuator, $on, $duty === null ? null : (int) $duty, 'web', 'Perintah dari dashboard');
        } catch (\DomainException $e) {
            Http::fail($e->getMessage(), 422, 'safety_block');
            return;
        }

        Http::ok([
            'result'   => $result,
            'controls' => Actuators::state($device()),
            'command'  => Actuators::target($device()),
            'state'    => DeviceState::pulse($device()),
        ], $result['changed']
            ? ucfirst($actuator) . ' ' . ($on ? 'dinyalakan' : 'dimatikan') . '.'
            : 'Tidak ada perubahan.');
        return;
    }

    if ($path === '/api/controls/all-off' && $method === 'POST') {
        $reason = Http::str('reason', 'Tombol darurat ditekan dari dashboard');
        Actuators::allOff($device(), 'safety', $reason);
        Settings::save(['auto_mode' => 0]);

        Http::ok([
            'controls' => Actuators::state($device()),
            'command'  => Actuators::target($device()),
        ], 'Semua aktuator dimatikan dan mode otomatis dinonaktifkan.');
        return;
    }

    /* ==================== ALERT ==================== */

    if ($path === '/api/alerts') {
        Http::ok([
            'active'  => Alerts::active($device()),
            'history' => Alerts::history($device(), Http::int('limit', 40)),
        ]);
        return;
    }

    if ($path === '/api/alerts/acknowledge' && $method === 'POST') {
        $id = Http::int('id', 0);
        if ($id <= 0) {
            Http::ok(['updated' => Alerts::acknowledgeAll($device())], 'Semua peringatan ditutup.');
            return;
        }
        $ok = Alerts::acknowledge($id, $device());
        Http::ok(['updated' => $ok ? 1 : 0, 'active' => Alerts::active($device())], 'Peringatan ditutup.');
        return;
    }

    if ($path === '/api/alerts/clear' && $method === 'POST') {
        $removed = Alerts::clearResolved($device());
        Http::ok(['removed' => $removed, 'active' => Alerts::active($device)], 'Riwayat peringatan dibersihkan.');
        return;
    }

    /* ==================== PENGATURAN ==================== */

    if ($path === '/api/settings/metadata') {
        Http::ok(['items' => Settings::metadata(), 'profiles' => array_values(Profile::all())]);
        return;
    }

    if ($path === '/api/settings' && $method === 'GET') {
        Http::ok([
            'values'   => Settings::all(true),
            'metadata' => Settings::metadata(),
            'profiles' => array_values(Profile::all()),
        ]);
        return;
    }

    if ($path === '/api/settings' && in_array($method, ['PUT', 'POST', 'PATCH'], true)) {
        $payload = Http::input();

        // Tidak ada konsep akun di dashboard, jadi abaikan kunci ini bila
        // masih terkirim oleh klien lama.
        unset($payload['current_password'], $payload['confirm_password'], $payload['new_password']);

        if ($payload === []) {
            Http::fail('Tidak ada setting yang dikirim.', 422, 'empty_payload');
            return;
        }

        try {
            $results = Settings::save($payload);
        } catch (\DomainException $e) {
            Http::fail($e->getMessage(), 422, 'invalid_setting');
            return;
        }

        $failed = array_values(array_filter($results, static fn(array $r): bool => !$r['ok']));
        Settings::flush();
        Profile::flush();

        // kontrol otomatis dievaluasi ulang setelah batas berubah
        $auto = AutoControl::tick($device());

        if ($failed !== []) {
            Http::json([
                'success' => false,
                'code'    => 'partial_failure',
                'message' => 'Sebagian setting gagal disimpan.',
                'data'    => [
                    'saved'  => array_values(array_filter($results, static fn(array $r): bool => $r['ok'])),
                    'failed' => $failed,
                    'values' => Settings::all(true),
                ],
                'time'    => date('c'),
            ], 207);
            return;
        }

        $pulse = DeviceState::pulse($device());
        $pulse['reading'] = shapeCurrentReading($pulse['reading'] ?? null, $pulse['trend'] ?? [], $pulse['controls'] ?? []);
        $pulse['advisor'] = shapeAdvisor($pulse['advisor']);

        Http::ok([
            'saved'   => $results,
            'values'  => Settings::all(true),
            'auto'    => $auto,
            'state'   => $pulse,
        ], count($results) . ' setting disimpan.');
        return;
    }

    /* ==================== PERANGKAT ==================== */

    if ($path === '/api/devices') {
        $onlineTimeout = Settings::int('offline_timeout');
        $stmt = Db::pdo()->prepare('SELECT * FROM `devices` ORDER BY `is_active` DESC, `id` ASC');
        $stmt->execute();
        $devices = [];

        foreach ($stmt->fetchAll() as $row) {
            $seen = $row['last_seen_at'] ? strtotime((string) $row['last_seen_at']) : null;
            $age  = $seen ? time() - $seen : null;
            $devices[] = [
                'device_code'   => $row['device_code'],
                'name'          => $row['name'],
                'location'      => $row['location'],
                'profile_code'  => $row['profile_code'],
                'is_active'     => (bool) $row['is_active'],
                'last_seen'     => $seen ? date('c', $seen) : null,
                'last_seen_ago' => $age,
                'online'        => $age !== null && $age <= $onlineTimeout,
                'last_ip'       => $row['last_ip'],
                'firmware'      => $row['firmware'],
            ];
        }

        $keyStmt = Db::pdo()->query('SELECT * FROM `api_keys` WHERE is_active = 1 ORDER BY `id` DESC');
        $keys = [];
        foreach ($keyStmt->fetchAll() as $row) {
            $used = $row['last_used_at'] ? strtotime((string) $row['last_used_at']) : null;
            $keys[] = [
                'id'            => (int) $row['id'],
                'device_code'   => $row['device_code'],
                'label'         => $row['label'],
                'scopes'        => $row['scopes'],
                'is_active'     => (bool) $row['is_active'],
                'last_used_at'  => $used ? date('c', $used) : null,
                'last_used_ago' => $used ? time() - $used : null,
                'created_at'    => date('c', strtotime((string) $row['created_at'])),
                'key_preview'   => substr((string) $row['key_hash'], 0, 10) . '...',
            ];
        }

        Http::ok(['devices' => $devices, 'api_keys' => $keys]);
        return;
    }

    if ($path === '/api/devices/api-key' && $method === 'POST') {
        $deviceCode = Http::str('device_code', $device());
        $label      = Http::str('label', 'Perangkat IoT');
        $plain      = Auth::generateKey();

        $stmt = Db::pdo()->prepare(
            'INSERT INTO `api_keys` (device_code, label, key_hash, scopes, is_active)
             VALUES (?, ?, ?, "sensor:write,control:read", 1)'
        );
        $stmt->execute([$deviceCode, $label, Auth::hashKey($plain)]);

        Http::ok([
            'api_key'     => $plain,
            'id'          => (int) Db::pdo()->lastInsertId(),
            'device_code' => $deviceCode,
            'label'       => $label,
        ], 'API key dibuat. Simpan sekarang, nilainya tidak akan ditampilkan lagi.');
        return;
    }

    if ($path === '/api/devices/api-key' && $method === 'DELETE') {
        $id = Http::int('id', 0);
        $stmt = Db::pdo()->prepare('UPDATE `api_keys` SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);

        Http::ok(['id' => $id], 'API key dinonaktifkan.');
        return;
    }

    /* ==================== UTILITAS ==================== */

    if ($path === '/api/weather') {
        Http::ok(Weather::current());
        return;
    }

    if ($path === '/api/maintenance/purge' && $method === 'POST') {
        $days = Http::int('days', Settings::int('retention_days'));
        $removed = Readings::purgeOlderThan(max(1, $days));
        Http::ok(['removed' => $removed, 'retention_days' => $days], $removed . ' baris lama dihapus.');
        return;
    }

    /* ==================== 404 ==================== */

    Http::fail('Endpoint tidak ditemukan: ' . $path, 404, 'not_found');
});
