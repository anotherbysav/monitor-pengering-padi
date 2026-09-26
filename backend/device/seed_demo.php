<?php
/**
 * Pembuat data sensor simulasi (dipakai setup.php --demo dan simulator).
 */

declare(strict_types=1);

use App\Readings;

/**
 * @return int jumlah baris yang dibuat
 */
function seed_demo_readings(string $deviceCode, int $minutes = 1440, int $stepSeconds = 120): int
{
    $pdo = \App\Db::pdo();
    $pdo->exec('DELETE FROM `readings` WHERE device_code = ' . $pdo->quote($deviceCode));

    $rows = [];
    $now  = time();
    $start = $now - $minutes * 60;

    // kondisi awal: padi panen basah
    $temp  = 26.0;
    $moist = 27.5;

    for ($t = $start; $t <= $now; $t += $stepSeconds) {
        $hour = (int) date('G', $t);

        // heater menyala bila suhu < 36, kipas menyala setelah pukul 8
        $heaterOn = $temp < 35.5;
        $fanOn    = $hour >= 8 && $hour <= 17;

        if ($heaterOn) {
            $temp += 0.22;
        } else {
            $temp -= 0.10;
        }
        $temp += sin($t / 900) * 0.35;              // noise
        $temp  = max(22.0, min(48.0, $temp));

        // RH ambient mengikuti pola harian
        $rh = 78 - 18 * sin((($hour - 8) / 24) * 2 * M_PI) + 6 * sin($t / 500);
        $rh = max(45.0, min(96.0, $rh));

        if ($fanOn && $temp > 33) {
            $drop = 0.0125 * (($temp - 32) / 8) * ($fanOn ? 1 : 0.2);
            $moist = max(11.5, $moist - $drop);
        }

        $rows[] = [
            $deviceCode,
            date('Y-m-d H:i:s', $t),
            round($temp, 2),
            round($moist, 2),
            round(28.0 + 3 * sin((($hour - 9) / 24) * 2 * M_PI), 2),
            round($rh, 2),
            $heaterOn ? 1 : 0,
            $heaterOn ? 80 : 0,
            $fanOn ? 1 : 0,
            $fanOn ? 80 : 0,
            'simulator',
        ];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `readings`
          (device_code, recorded_at, temp_c, moisture_pct, ambient_temp_c, ambient_rh_pct, heater_on, heater_duty, fan_on, fan_duty, source)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $stmt->execute($r);
    }
    $pdo->commit();

    return count($rows);
}

/*
 * Jalankan langsung dari CLI:
 *   php backend/device/seed_demo.php [DEVICE_CODE] [MENIT] [STEP_DETIK]
 */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once dirname(__DIR__) . '/src/bootstrap.php';
    require_once __FILE__;

    $code    = $argv[1] ?? 'DRYER-01';
    $minutes = isset($argv[2]) ? (int) $argv[2] : 1440;
    $step    = isset($argv[3]) ? (int) $argv[3] : 120;

    $made = seed_demo_readings($code, $minutes, $step);
    echo 'Data simulasi ' . $code . ': ' . $made . ' baris'
        . ' (' . $minutes . ' menit, step ' . $step . " detik)\n";
}
