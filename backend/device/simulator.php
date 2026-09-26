<?php
/**
 * ============================================================================
 *  SIMULATOR PERANGKAT  -  php backend/device/simulator.php
 * ============================================================================
 *  Mengirim data sensor ke server API seperti modul IoT sungguhan.
 *  Berguna untuk menguji dashboard tanpa hardware.
 *
 *  Contoh:
 *    php backend/device/simulator.php --url=http://localhost:8000 --key=API_KEY_ANDA
 *    php backend/device/simulator.php --once
 *    php backend/device/simulator.php --mode=manual --key=...   (kirim 1x lalu berhenti)
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Hanya bisa dijalankan dari command line.\n");
}

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $arg, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}

$baseUrl = rtrim($opt['url'] ?? 'http://localhost:8000', '/');
$apiKey  = $opt['key'] ?? getenv('MSK_API_KEY') ?: '';
$once    = isset($opt['once']);
$manual  = ($opt['mode'] ?? 'auto') === 'manual';
$verbose = !isset($opt['quiet']);

if ($apiKey === '') {
    exit("API key wajib diisi. Contoh:\n  php backend/device/simulator.php --key=msk_abc123...\n"
       . "Buat kunci melalui dashboard: menu Perangkat & API.\n");
}

echo "Simulator Monitor Suhu & Kelembapan\n";
echo "  Target : {$baseUrl}/api/sensor/ingest\n";
echo "  Mode   : " . ($manual ? 'manual (kirim 1x)' : 'otomatis') . "\n";
echo "  Interval: " . (int) ($opt['interval'] ?? 3) . " detik\n\n";

$temp  = (float) ($opt['temp'] ?? 27.5);
$moist = (float) ($opt['moist'] ?? 26.0);
$iter  = 0;
$ok    = 0;
$fail  = 0;

/* ---------- jika mode manual: tunggu perintah dari stdin ---------- */
if ($manual) {
    echo "Masukkan nilai (contoh: 38.5 22.4) lalu Enter. Kosongkan untuk keluar.\n";
    while (true) {
        echo "suhu/Suhu#Kelembapan > ";
        $line = trim((string) fgets(STDIN));
        if ($line === '' || strtolower($line) === 'q') {
            break;
        }
        $parts = preg_split('/[\s,]+/', $line);
        $t = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : $temp;
        $m = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : $moist;

        $res = post([
            'temp_c'       => $t,
            'moisture_pct' => $m,
            'heater_on'    => $t < 35,
            'fan_on'       => true,
            'fan_duty'     => 80,
            'heater_duty'  => 80,
            'source'       => 'simulator-manual',
        ]);

        if ($res['ok']) {
            $adv = $res['data']['advisor'] ?? [];
            printf(
                "  tersimpan  |  %s  |  ETA: %s  |  laju %.2f%%/jam\n",
                $adv['label'] ?? '-',
                $adv['eta_text'] ?? '-',
                $adv['drying_rate'] ?? 0
            );
        } else {
            echo '  GAGAL: ' . ($res['error'] ?? 'tidak diketahui') . "\n";
        }
    }
    exit(0);
}

/* ---------- mode otomatis ---------- */
while (true) {
    $iter++;

    // simulasi proses pengeringan: Initially ding, mulai dipanaskan
    $phase = fmod((time() / 3600), 24);
    $fanOn = $phase > 7 && $phase < 18;

    if ($fanOn) {
        $heat  = 0.30;
        $drop  = 0.014 * max(0.2, ($temp - 32) / 8);
        $moist = max(12.0, $moist - $drop);
    } else {
        $heat = -0.08;
    }
    $temp += $heat + sin(microtime(true) * 0.6) * 0.25;
    $temp  = max(23.0, min(49.0, $temp));
    $heaterOn = $temp < 36.0;

    $rh = max(50.0, min(94.0, 80 - 16 * sin((($phase - 8) / 24) * 2 * M_PI)));

    $res = post([
        'temp_c'         => round($temp, 2),
        'moisture_pct'   => round($moist, 2),
        'ambient_temp_c' => round(28 + 3 * sin((($phase - 9) / 24) * 2 * M_PI), 2),
        'ambient_rh_pct' => round($rh, 1),
        'heater_on'      => $heaterOn,
        'heater_duty'    => $heaterOn ? 80 : 0,
        'fan_on'         => $fanOn,
        'fan_duty'       => $fanOn ? 80 : 0,
        'source'         => 'simulator',
    ]);

    if ($res['ok']) {
        $ok++;
        if ($verbose) {
            $adv  = $res['data']['advisor'] ?? [];
            $auto = $res['data']['auto'] ?? [];
            printf(
                "[%s] #%d  T=%.1fC  M=%.1f%%  %-32s ETA=%-10s laju=%.2f%%/jam  auto:%s\n",
                date('H:i:s'),
                $iter,
                $temp,
                $moist,
                $adv['label'] ?? '-',
                $adv['eta_text'] ?? '-',
                $adv['drying_rate'] ?? 0,
                implode(',', $auto['actions'] ?? []) ?: '-'
            );
        }
    } else {
        $fail++;
        if ($verbose) {
            printf("[%s] #%d  GAGAL: %s\n", date('H:i:s'), $iter, $res['error'] ?? 'tidak diketahui');
        }
    }

    if ($once) {
        printf("\nSelesai: %d berhasil, %d gagal.\n", $ok, $fail);
        exit($fail > 0 && $ok === 0 ? 1 : 0);
    }

    sleep((int) ($opt['interval'] ?? 3));
}

/**
 * @return array{ok:bool,data?:array,error?:string}
 */
function post(array $payload): array
{
    global $baseUrl, $apiKey;

    $ch = curl_init($baseUrl . '/api/sensor/ingest');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Api-Key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '') {
        return ['ok' => false, 'error' => $err];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Respons bukan JSON (HTTP ' . $code . ')'];
    }
    if (($json['success'] ?? false) !== true) {
        return ['ok' => false, 'error' => ($json['message'] ?? 'ditolak') . ' (HTTP ' . $code . ')'];
    }
    return ['ok' => true, 'data' => $json['data'] ?? []];
}
