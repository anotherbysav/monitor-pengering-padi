<?php
/**
 * Logika kontrol otomatis: menentukan kondisi heater & fan dari data sensor
 * dan batas yang diatur user, dengan pengaman (safety interlock).
 *
 * Dipanggil setiap kali ada reading baru, baik dari perangkat map maupun
 * dari dashboard (untuk re-evaluasi setelah user mengubah batas).
 */

declare(strict_types=1);

namespace App;

final class AutoControl
{
    public static function tick(string $deviceCode): array
    {
        if (!Settings::bool('auto_mode')) {
            return ['mode' => 'manual', 'actions' => []];
        }

        $reading = Readings::latest($deviceCode);
        if ($reading === null) {
            return ['mode' => 'auto', 'actions' => []];
        }

        $temp  = (float) $reading['temp_c'];
        $moist = (float) $reading['moisture_pct'];
        $state = Actuators::state($deviceCode);
        $rh    = $reading['ambient_rh_pct'] !== null ? (float) $reading['ambient_rh_pct'] : Settings::float('ambient_rh_default');

        $hyst   = max(0.1, Settings::float('temp_hysteresis'));
        $tMin   = Settings::float('temp_min');
        $tMax   = Settings::float('temp_max');
        $tOpt   = Settings::float('temp_optimal');
        $tLimit = Settings::float('temp_limit');
        $mStop  = Settings::float('moisture_stop');
        $mWet   = Settings::float('moisture_wet');

        $heatDuty = max(0, min(100, Settings::int('heater_duty')));
        $fanDuty  = max(0, min(100, Settings::int('fan_duty')));

        $wantHeater = false;
        $wantFan    = false;
        $reasonH    = '';
        $reasonF    = '';
        $blocked    = false;
        $blockMsg   = '';

        /* ---------- keputusan kipas ---------- */
        if ($moist > $mStop + 0.5 && $temp >= $tMin - 2 && $rh < 90) {
            $wantFan = true;
            // duty kipas naik sebanding dengan kelembapan
            $target = $fanDuty;
            if ($moist > $mWet) {
                $target = max($target, min(100, $fanDuty + 15));
            }
            $reasonF = "Kelembapan {$moist}% > batas berhenti {$mStop}%, kipas untuk circulasi udara.";
        } elseif ($temp > $tMax) {
            $wantFan = true;
            $target  = max(60, $fanDuty);
            $reasonF = "Suhu {$temp}C di atas batas atas, kipas untuk mendinginkan.";
        } else {
            $target = 0;
            $reasonF = $moist <= $mStop + 0.5
                ? "Kelembapan sudah mencapai target, kipas dimatikan."
                : "Suhu belum mendukung pengeringan, kipas menunggu.";
        }

        /* ---------- keputusan heater ---------- */
        if ($temp >= $tLimit) {
            $blocked  = true;
            $blockMsg = "Suhu {$temp}C mencapai batas keras {$tLimit}C. Heater dikunci mati.";
            $reasonH  = $blockMsg;
        } elseif ($moist <= $mStop) {
            $reasonH = "Kelembapan sudah mencapai batas berhenti, heater dimatikan.";
        } elseif ($temp > $tMax - $hyst) {
            $reasonH = "Suhu {$temp}C mendekati batas atas {$tMax}C, heater dimatikan.";
        } elseif ($temp < $tMin + $hyst && $moist > $mStop) {
            $wantHeater = true;
            // duty lebih rendah saat mendekati batas atas (proporsional)
            $span  = max(1.0, $tMax - $tMin);
            $ratio = min(1.0, max(0.0, ($temp - $tMin) / $span));
            $targetH = (int) round(max(35, $heatDuty * (1.0 - 0.45 * $ratio)));
            $reasonH = "Suhu {$temp}C di bawah batas bawah {$tMin}C, heater memanaskan (duty terukur).";
        } else {
            $targetH = 0;
            $reasonH = $temp >= $tMin
                ? "Suhu {$temp}C sudah pada rentang target {$tMin}-{$tMax}C, heater mati."
                : "Menunggu kondisi untuk memanaskan.";
        }

        /* ---------- terapkan ---------- */
        $actions = [];

        // Bandingkan dengan perintah terakhir (bukan state aktual perangkat),
        // supaya tidak mencatat perintah yang sama berulang kali saat
        // perangkat belum sempat melapor.
        $heaterCmd = $wantHeater && !$blocked;
        $heaterDutyNow = $heaterCmd ? max(20, $targetH) : 0;
        $currentHeaterOn = (bool) $state['heater']['commanded'];
        $currentHeaterDuty = (int) $state['heater']['commanded_duty'];

        if ($currentHeaterOn !== $heaterCmd || ($heaterCmd && $currentHeaterDuty !== $heaterDutyNow)) {
            try {
                $res = Actuators::set($deviceCode, 'heater', $heaterCmd, $heaterDutyNow, 'auto', $reasonH);
                if ($res['changed']) {
                    $actions[] = 'heater';
                }
            } catch (\DomainException $e) {
                $blockMsg = $e->getMessage();
            }
        }

        $fanDutyNow = $wantFan ? $target : 0;
        $currentFanOn = (bool) $state['fan']['commanded'];
        $currentFanDuty = (int) $state['fan']['commanded_duty'];

        if ($currentFanOn !== $wantFan || $currentFanDuty !== $fanDutyNow) {
            $res = Actuators::set($deviceCode, 'fan', $wantFan, $fanDutyNow, 'auto', $reasonF);
            if ($res['changed']) {
                $actions[] = 'fan';
            }
        }

        return [
            'mode'       => 'auto',
            'actions'    => $actions,
            'heater'     => ['on' => $heaterCmd, 'duty' => $heaterDutyNow],
            'fan'        => ['on' => $wantFan, 'duty' => $fanDutyNow],
            'reasons'    => ['heater' => $reasonH, 'fan' => $reasonF],
            'blocked'    => $blocked,
            'block_msg'  => $blockMsg,
        ];
    }

    /**
     * Peringatan ambang batas + auto matikan bila suhu melewati batas keras.
     * Dipanggil setelah setiap reading.
     */
    public static function watchAlerts(string $deviceCode): void
    {
        $reading = Readings::latest($deviceCode);
        if ($reading === null) {
            return;
        }

        $temp  = (float) $reading['temp_c'];
        $moist = (float) $reading['moisture_pct'];
        $tMin  = Settings::float('temp_min');
        $tMax  = Settings::float('temp_max');
        $tLim  = Settings::float('temp_limit');
        $mStop = Settings::float('moisture_stop');
        $mDry  = Settings::float('moisture_dry');

        // suhu
        if ($temp >= $tLim) {
            Alerts::raise($deviceCode, 'suhu_kritis', 'critical',
                "Suhu {$temp} C melewati batas keras {$tLim} C", $temp, $tLim);
            if (Settings::bool('safety_auto_off')) {
                try {
                    Actuators::set($deviceCode, 'heater', false, 0, 'safety', 'Auto matikan: suhu melewati batas keras');
                    Actuators::set($deviceCode, 'fan', true, 100, 'safety', 'Auto: kipas maksimal untuk mendinginkan');
                } catch (\DomainException) {
                }
            }
        } else {
            Alerts::resolve($deviceCode, 'suhu_kritis');
        }

        if ($temp > $tMax) {
            Alerts::raise($deviceCode, 'suhu_tinggi', 'warning',
                "Suhu {$temp} C di atas batas atas {$tMax} C", $temp, $tMax);
        } else {
            Alerts::resolve($deviceCode, 'suhu_tinggi');
        }

        if ($temp < $tMin - 3) {
            Alerts::raise($deviceCode, 'suhu_rendah', 'warning',
                "Suhu {$temp} C di bawah batas bawah {$tMin} C", $temp, $tMin);
        } else {
            Alerts::resolve($deviceCode, 'suhu_rendah');
        }

        // kelembapan
        if ($moist <= $mStop) {
            Alerts::raise($deviceCode, 'kelembapan_optimal', 'info',
                "Kelembapan {$moist}% sudah mencapai target", $moist, $mStop);
        } else {
            Alerts::resolve($deviceCode, 'kelembapan_optimal');
        }

        if ($moist < $mDry) {
            Alerts::raise($deviceCode, 'kelembapan_kering', 'warning',
                "Kelembapan {$moist}% terlalu kering (batas bawah {$mDry}%)", $moist, $mDry);
        } else {
            Alerts::resolve($deviceCode, 'kelembapan_kering');
        }

        // sensor/OFfline
        $age = self::ageSeconds($reading);
        $limit = max(30, Settings::int('stale_reading_min') * 60);
        if ($age > $limit) {
            Alerts::raise($deviceCode, 'perangkat_offline', 'critical',
                "Tidak ada data sensor selama " . self::ago($age), $age, $limit);
        } else {
            Alerts::resolve($deviceCode, 'perangkat_offline');
        }
    }

    public static function ageSeconds(array $reading): int
    {
        $ts = strtotime((string) $reading['recorded_at']);
        return $ts === false ? PHP_INT_MAX : max(0, time() - $ts);
    }

    private static function ago(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' detik';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . ' menit';
        }
        return floor($seconds / 3600) . ' jam';
    }
}
