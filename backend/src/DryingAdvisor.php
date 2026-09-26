<?php
/**
 * ============================================================================
 *  MESIN REKOMENDASI WAKTU PENGERINGAN
 * ============================================================================
 *  Menghitung apakah saat ini waktu yang tepat untuk mengeringkan, estimasi
 *  lama selesai, dan tindakan yang perlu dilakukan.
 *
 *  Model laju pengeringan (lapisan tipis):
 *
 *     dryRate (%/jam) = base_rate x f_T x f_A x f_M x f_L x f_W
 *
 *     f_T  faktor suhu   : 0 di bawah batas bawah; 1.0 di suhu optimal;
 *                          1.15 di batas atas; 0.30 di batas keras; 0 di atasnya
 *     f_A  faktor udara  : kipas mati 0.20, kipas nyala 0.25 + 0.75*duty
 *     f_M  faktor lembap : 0.30 + 0.70 * (M - stop) / (awal - stop)  [0.30..1.00]
 *     f_L  ketebalan     : 1 / (1 + 0.55 * (tebal_mm - 1))
 *     f_W  cuaca / RH    : 1 + 0.20 * (RH - 65) / 35                 [0.75..1.30]
 *
 *     ETA (jam) = (kelembapan_sekarang - kelembapan_berhenti) / dryRate
 * ============================================================================
 */

declare(strict_types=1);

namespace App;

final class DryingAdvisor
{
    public const OFFLINE   = 'OFFLINE';
    public const BAHAYA    = 'BAHAYA';
    public const PEMANASAN = 'PEMANASAN';
    public const OPTIMAL   = 'OPTIMAL';
    public const MENUNGGU  = 'MENUNGGU';
    public const JEDA      = 'JEDA';
    public const SELESAI   = 'SELESAI';

    /**
     * @param array|null $reading  reading sensor terakhir
     * @param array      $profile  profil material aktif
     * @param array      $controls ['heater'=>['on'=>bool,'duty'=>int], 'fan'=>[...]]
     * @param array      $context  ['age_seconds','ambient_rh','ambient_temp','trend','weather_code']
     */
    public static function evaluate(?array $reading, array $profile, array $controls, array $context = []): array
    {
        $tMin    = (float) $profile['safe_temp_min'];
        $tOpt    = (float) $profile['optimal_temp'];
        $tMax    = (float) $profile['safe_temp_max'];
        $tLimit  = (float) $profile['limit_temp_max'];
        $mStop   = (float) $profile['stop_moisture'];
        $mTarget = (float) $profile['target_moisture'];
        $mInit   = max((float) $profile['initial_moisture'], $mTarget + 0.1);
        $thick   = max(0.2, (float) $profile['thickness']);
        $base    = (float) $profile['base_rate'];

        $staleLimit = max(30, Settings::int('stale_reading_min') * 60);
        $age        = (int) ($context['age_seconds'] ?? 0);

        if ($reading === null) {
            return self::result(
                self::OFFLINE, 'Menunggu Data Sensor', 'slate', 0, null, null, null, 0,
                [
                    ['key' => 'data',     'label' => 'Data sensor',            'state' => 'bad',  'detail' => 'Belum ada data dari perangkat.'],
                    ['key' => 'temp',     'label' => 'Suhu dalam batas aman',  'state' => 'wait', 'detail' => 'Menunggu pembacaan sensor.'],
                    ['key' => 'moisture', 'label' => 'Kelembapan di atas stop', 'state' => 'wait', 'detail' => 'Menunggu pembacaan sensor.'],
                ],
                [
                    'tone' => 'slate', 'icon' => 'plug',
                    'items' => ['Pastikan perangkat online, API key benar, dan server dapat dijangkau.'],
                ],
                [],
                [],
                [
                    'temp_min' => $tMin, 'temp_max' => $tMax, 'temp_optimal' => $tOpt, 'temp_limit' => $tLimit,
                    'moisture_target' => $mTarget, 'moisture_stop' => $mStop, 'moisture_initial' => $mInit,
                    'profile' => ['code' => $profile['code'], 'name' => $profile['name']],
                ]
            );
        }

        $temp  = (float) $reading['temp_c'];
        $moist = (float) $reading['moisture_pct'];
        $rh    = isset($context['ambient_rh']) && $context['ambient_rh'] !== null
            ? (float) $context['ambient_rh']
            : Settings::float('ambient_rh_default');
        $ambT  = isset($context['ambient_temp']) && $context['ambient_temp'] !== null
            ? (float) $context['ambient_temp']
            : $temp;

        $heaterOn = !empty($controls['heater']['on']);
        $fanOn    = !empty($controls['fan']['on']);
        $fanDuty  = (int) ($controls['fan']['duty'] ?? 0);
        $heatDuty = (int) ($controls['heater']['duty'] ?? 0);

        /* ---------------- 1. faktor laju pengeringan ---------------- */
        $fT = self::tempFactor($temp, $tMin, $tOpt, $tMax, $tLimit);
        $fA = $fanOn ? 0.25 + 0.75 * (self::clamp($fanDuty, 0, 100) / 100) : 0.20;
        $fM = self::clamp(0.30 + 0.70 * (($moist - $mStop) / max(0.1, $mInit - $mStop)), 0.30, 1.00);
        $fL = 1 / (1 + 0.55 * max(0.0, $thick - 1.0));
        $fW = self::clamp(1 + 0.20 * (($rh - 65) / 35), 0.75, 1.30);

        $rate     = round($base * $fT * $fA * $fM * $fL * $fW, 3);
        $removing = max(0.0, $moist - $mStop);
        $eta      = null;
        if ($removing <= 0.001) {
            $eta = 0.0;
        } elseif ($rate > 0.0005) {
            $maxH = (float) (Db::config()['advisor']['eta_max_hours'] ?? 240);
            $eta  = min($maxH, $removing / $rate);
        }

        $etaAt    = $eta === null ? null : date('c', time() + (int) round($eta * 3600));
        $progress = self::clamp((($mInit - $moist) / max(0.1, $mInit - $mStop)) * 100, 0, 100);

        /* ---------------- 2. skor kondisi 0-100 ---------------- */
        $sTemp    = self::tempScore($temp, $tMin, $tOpt, $tMax, $tLimit);
        $sAir     = $fanOn ? self::clamp(55 + $fanDuty * 0.45, 0, 100) : 25.0;
        $sWeather = self::clamp(100 - max(0.0, $rh - 55) * 2.2, 20, 100);
        $score    = (int) round(self::clamp(
            $sTemp * 0.42 + $sAir * 0.24 + $sWeather * 0.20 + ($removing > 0.001 ? 100 : 100) * 0.14,
            0, 100
        ));

        /* ---------------- 3. status ---------------- */
        $fault    = $temp > Settings::float('safety_fault_temp');
        $overHeat = $temp >= $tLimit;
        $tooHot   = $temp > $tMax;
        $tooCold  = $temp < $tMin;
        $done     = $moist <= $mStop;
        $rainy    = self::isRainy($context['weather_code'] ?? null);
        $dampAir  = $rh >= 88;
        $offline  = $age > $staleLimit;

        $verdict = self::classify(
            $fault, $overHeat, $tooHot, $tooCold, $done, $dampAir, $rainy, $offline,
            $temp, $moist, $rh, $tMin, $tOpt, $tMax, $tLimit, $mStop, $mTarget,
            $heaterOn, $heatDuty, $fanOn, $fanDuty, $thick
        );

        /* ---------------- 4. checklist ---------------- */
        $conditions = [
            [
                'key' => 'temp', 'label' => "Suhu dalam batas aman ({$tMin}-{$tMax} C)",
                'state'  => ($tooCold && !($tooHot || $overHeat)) ? 'warn' : (($tooHot || $overHeat) ? 'bad' : 'ok'),
                'detail' => "Terukur " . self::num($temp) . " C, titik optimal " . self::num($tOpt) . " C",
            ],
            [
                'key' => 'moisture', 'label' => "Kelembapan masih di atas batas berhenti ({$mStop} %)",
                'state'  => $done ? 'ok' : 'warn',
                'detail' => $done
                    ? "Tercapai (" . self::num($moist) . " % <= " . self::num($mStop) . " %)"
                    : "Perlu turun " . self::num($removing) . " % lagi",
            ],
            [
                'key' => 'airflow', 'label' => 'Aliran udara (kipas) aktif',
                'state'  => $fanOn ? 'ok' : 'bad',
                'detail' => $fanOn ? "Kipas nyala, duty {$fanDuty}%" : 'Kipas mati, laju pengeringan turun drastis',
            ],
            [
                'key' => 'weather', 'label' => 'RH ambient < 88 %',
                'state'  => ($rainy || $dampAir) ? 'bad' : ($rh > 78 ? 'warn' : 'ok'),
                'detail' => "RH ambient " . self::num($rh) . '%' . ($rainy ? ' (hujan terdeteksi)' : ''),
            ],
            [
                'key' => 'heater', 'label' => 'Heater bekerja sesuai kebutuhan',
                'state'  => $tooCold && !$heaterOn ? 'warn' : 'ok',
                'detail' => $tooCold
                    ? ($heaterOn ? "Memanaskan, duty {$heatDuty}%" : 'Heater mati sementara suhu di bawah batas bawah')
                    : ($heaterOn ? "Nyala, duty {$heatDuty}%" : 'Mati, suhu sudah pada rentang target'),
            ],
            [
                'key' => 'data', 'label' => 'Data sensor segar',
                'state'  => $offline ? 'bad' : 'ok',
                'detail' => $offline
                    ? 'Terakhir ' . self::ago($age) . ' lalu (batas ' . (int) ($staleLimit / 60) . ' menit)'
                    : 'Diperbarui ' . self::ago($age) . ' lalu',
            ],
        ];

        /* ---------------- 5. waktu terbaik ---------------- */
        $best = self::bestTime($verdict['status'], $rh, $temp, $tMin, $tOpt, $rainy, $dampAir);

        /* ---------------- 6. mutu hasil ---------------- */
        $quality = self::quality($moist, $temp, $tOpt, $tMax, $tLimit, $mTarget, $mStop, $age);

        $factors = [
            ['name' => 'Suhu',            'value' => $fT, 'max' => 1.15, 'note' => self::num($temp) . ' C'],
            ['name' => 'Aliran udara',    'value' => $fA, 'max' => 1.00, 'note' => $fanOn ? "kipas {$fanDuty}%" : 'kipas mati'],
            ['name' => 'Gradien lembap',  'value' => $fM, 'max' => 1.00, 'note' => self::num($moist) . ' %'],
            ['name' => 'Ketebalan lapis', 'value' => $fL, 'max' => 1.00, 'note' => self::num($thick) . ' mm'],
            ['name' => 'Cuaca / RH',      'value' => $fW, 'max' => 1.30, 'note' => self::num($rh) . ' %'],
        ];

        return self::result(
            $verdict['status'], $verdict['label'], $verdict['tone'], $score,
            $rate, $eta, $etaAt, $progress, $conditions,
            ['tone' => $verdict['tone'], 'icon' => $verdict['icon'], 'items' => $verdict['advice']],
            $factors,
            [
                'temp'         => self::num($temp),
                'moisture'     => self::num($moist),
                'ambient_temp' => self::num($ambT),
                'ambient_rh'   => self::num($rh),
                'heater'       => $heaterOn,
                'heater_duty'  => $heaterOn ? $heatDuty : 0,
                'fan'          => $fanOn,
                'fan_duty'     => $fanOn ? $fanDuty : 0,
                'temp_min'     => $tMin,
                'temp_max'     => $tMax,
                'temp_optimal' => $tOpt,
                'temp_limit'   => $tLimit,
                'moisture_target' => $mTarget,
                'moisture_stop'   => $mStop,
                'moisture_initial'=> $mInit,
                'moisture_remaining' => self::num($removing),
                'layer_thickness'   => self::num($thick),
                'mode'          => Settings::bool('auto_mode') ? 'auto' : 'manual',
                'best_time'     => $best,
                'quality'       => $quality,
                'observed_rate' => $context['trend']['moist_per_hour'] ?? null,
                'age_seconds'   => $age,
                'profile'       => ['code' => $profile['code'], 'name' => $profile['name']],
                // Nilai numerik mentah (tanpa format) untuk perhitungan lanjutan.
                // Field di atas sengaja berupa string siap-tampil.
                'metrics'       => [
                    'temp'            => $temp,
                    'moisture'        => $moist,
                    'ambient_temp'    => $ambT,
                    'ambient_rh'      => $rh,
                    'moisture_target' => $mTarget,
                    'moisture_stop'   => $mStop,
                    'moisture_initial'=> $mInit,
                    'moisture_remaining' => $removing,
                    'layer_thickness' => $thick,
                    'temp_min'        => $tMin,
                    'temp_max'        => $tMax,
                    'temp_optimal'    => $tOpt,
                    'temp_limit'      => $tLimit,
                ],
            ]
        );
    }

    /* ==================================================================
     *  Penentuan status + saran
     * ================================================================== */

    private static function classify(
        bool $fault, bool $overHeat, bool $tooHot, bool $tooCold, bool $done,
        bool $dampAir, bool $rainy, bool $offline,
        float $temp, float $moist, float $rh,
        float $tMin, float $tOpt, float $tMax, float $tLimit, float $mStop, float $mTarget,
        bool $heaterOn, int $heatDuty, bool $fanOn, int $fanDuty, float $thick
    ): array {
        $out = ['status' => self::MENUNGGU, 'label' => '', 'tone' => 'slate', 'icon' => 'clock', 'advice' => []];

        if ($overHeat) {
            $out['status'] = self::BAHAYA;
            $out['label']  = 'BAHAYA: Suhu Melebihi Batas Keras';
            $out['tone']   = 'rose';
            $out['icon']   = 'alert';
            $out['advice'] = [
                "Matikan heater SEGERA: suhu " . self::num($temp) . " C melewati batas keras " . self::num($tLimit) . " C.",
                'Periksa pemasangan thermocouple dan pastikan udara panas keluar (exhaust) tidak tersumbat.',
                'Kipas tetap dinyalakan untuk mendinginkan ruang pengering.',
            ];
            return $out;
        }

        if ($tooHot) {
            $out['status'] = self::BAHAYA;
            $out['label']  = 'Suhu Terlalu Tinggi - Hentikan Pemanasan';
            $out['tone']   = 'orange';
            $out['icon']   = 'alert';
            $out['advice'] = [
                "Heater harus mati: suhu " . self::num($temp) . " C sudah melewati batas atas " . self::num($tMax) . " C.",
                "Turunkan duty heater menjadi 0%, biarkan kipas tetap nyala sampai suhu < " . self::num($tMax) . " C.",
                'Bila sering terjadi, turunkan batas atas atau perbaiki kontrol histeresis.',
            ];
            return $out;
        }

        if ($fault) {
            $out['status'] = self::BAHAYA;
            $out['label']  = 'Sensor Suhu Tidak Normal';
            $out['tone']   = 'rose';
            $out['icon']   = 'sensor';
            $out['advice'] = [
                'Nilai suhu di luar rentang sensor yang wajar. Kemungkinan thermocouple短路 atau kabel terbalik.',
                'Semua actuator dimatikan sebagai tindakan pengaman.',
            ];
            return $out;
        }

        if ($done) {
            $out['status'] = self::SELESAI;
            $out['label']  = 'Pengeringan Selesai';
            $out['tone']   = 'sky';
            $out['icon']   = 'check';
            $out['advice'] = [
                "Kadar air " . self::num($moist) . " % sudah mencapai batas berhenti " . self::num($mStop) . " %.",
                $moist < 11.0
                    ? 'Hentikan pengeringan: kadar air terlalu kering (< 11 %) menyebabkan kehilangan bobot hasil.'
                    : 'Matikan heater, sisakan kipas 30 menit untuk penyamaan suhu, lalu simpan di wadah kedap udara.',
            ];
            return $out;
        }

        if ($rainy) {
            $out['status'] = self::JEDA;
            $out['label']  = 'Jeda: Cuaca Hujan';
            $out['tone']   = 'indigo';
            $out['icon']   = 'cloud';
            $out['advice'] = [
                'Hujan terdeteksi. Pengeringan di dalam ruang akan melambat karena RH tinggi.',
                'Tutup ventilasi sisi angin, atau jeda proses dan lanjutkan saat RH turun.',
            ];
            return $out;
        }

        if ($dampAir) {
            $out['status'] = self::JEDA;
            $out['label']  = 'Jeda: Udara Terlalu Lembap';
            $out['tone']   = 'indigo';
            $out['icon']   = 'cloud';
            $out['advice'] = [
                "RH ambient " . self::num($rh) . " % terlalu tinggi, penguapan air terhambat dan risiko jamur meningkat.",
                'Tunggu RH < 75 % atau pindahkan jadwal ke malam hari.',
            ];
            return $out;
        }

        if ($tooCold) {
            $out['status'] = self::PEMANASAN;
            $out['label']  = 'Pemanasan - Suhu Belum Cukup';
            $out['tone']   = 'amber';
            $out['icon']   = 'flame';
            $out['advice'] = [
                "Suhu " . self::num($temp) . " C masih di bawah batas bawah " . self::num($tMin) . " C.",
                $heaterOn
                    ? "Heater sudah memanaskan pada duty {$heatDuty}%. Tunggu sampai suhu mencapai batas bawah."
                    : 'Nyalakan heater untuk memanaskan ruang pengering.',
            ];
            if (!$fanOn) {
                $out['advice'][] = 'Sebaiknya kipas dinyalakan 30% saat pemanasan agar panas merata.';
            }
            return $out;
        }

        if (!$offline && $fanOn) {
            $out['status'] = self::OPTIMAL;
            $out['label']  = 'Waktu Pengeringan Optimal';
            $out['tone']   = 'emerald';
            $out['icon']   = 'sparkles';
            $out['advice'] = [
                "Kondisi ideal: suhu " . self::num($temp) . " C di dalam batas aman, kelembapan " . self::num($moist) . " % masih di atas " . self::num($mStop) . " %.",
                'Biarkan proses berjalan tanpa gangguan, target akhir ' . self::num($mTarget) . ' %.',
            ];
            if ($fanDuty < 60) {
                $out['advice'][] = 'Duty kipas di bawah 60%. Naikkan untuk mempercepat pengeringan.';
            }
            if ($temp > $tMax - 3) {
                $out['advice'][] = 'Suhu mendekati batas atas, turunkan duty heater menjadi 50-60% untuk merapikan.';
            }
            if ($thick > 3) {
                $out['advice'][] = 'Ketebalan lapisan ' . self::num($thick) . ' mm, dianjurkan untuk diaduk atau dipipihkan 1-2x.';
            }
            return $out;
        }

        $out['status'] = self::MENUNGGU;
        $out['label']  = $offline ? 'Menunggu Data Sensor' : 'Menunggu Kondisi Layak';
        $out['tone']   = 'slate';
        $out['icon']   = 'clock';
        $out['advice'] = [];
        if (!$fanOn) {
            $out['advice'][] = 'Nyalakan kipas: tanpa aliran udara pengeringan menjadi sangat lambat.';
        }
        if ($offline) {
            $out['advice'][] = 'Data sensor tidak pernah tiba, periksa koneksi jaringan dan API key perangkat.';
        }
        if ($out['advice'] === []) {
            $out['advice'][] = 'Kondisi saat ini belum memenuhi syarat pengeringan. Tunggu monitor berikutnya.';
        }
        return $out;
    }

    /* ==================================================================
     *  Helper
     * ================================================================== */

    private static function tempFactor(float $t, float $tMin, float $tOpt, float $tMax, float $tLimit): float
    {
        if ($t <= $tMin) {
            return 0.0;
        }
        if ($t <= $tOpt) {
            return self::clamp(($t - $tMin) / max(0.5, $tOpt - $tMin), 0, 1);
        }
        if ($t <= $tMax) {
            return 1.0 + 0.15 * (($t - $tOpt) / max(0.5, $tMax - $tOpt));
        }
        if ($t <= $tLimit) {
            return self::clamp(0.30 + (1.15 - 0.30) * (($tLimit - $t) / max(0.5, $tLimit - $tMax)), 0.30, 1.15);
        }
        return 0.0;
    }

    private static function tempScore(float $t, float $tMin, float $tOpt, float $tMax, float $tLimit): float
    {
        if ($t <= $tMin) {
            return self::clamp(40 - ($tMin - $t) * 8, 0, 40);
        }
        if ($t <= $tOpt) {
            return self::clamp(40 + 60 * (($t - $tMin) / max(0.5, $tOpt - $tMin)), 40, 100);
        }
        if ($t <= $tMax) {
            return 100.0;
        }
        if ($t <= $tLimit) {
            return self::clamp(100 - ($t - $tMax) * 12, 15, 100);
        }
        return 0.0;
    }

    private static function isRainy(mixed $code): bool
    {
        return $code !== null && in_array((int) $code, [61, 63, 65, 80, 81, 82, 95, 96, 99], true);
    }

    private static function bestTime(string $status, float $rh, float $temp, float $tMin, float $tOpt, bool $rainy, bool $dampAir): array
    {
        if ($status === self::SELESAI) {
            return [
                'verdict' => 'selesai',
                'label'   => 'Waktu pengeringan sudah tercapai',
                'reason'  => 'Kadar air sudah mencapai batas, tidak perlu menunggu jadwal tertentu.',
            ];
        }
        if ($rainy) {
            return [
                'verdict' => 'tunggu',
                'label'   => 'Tunggu RH ambient turun',
                'reason'  => 'Hujan aktif. Pengeringan paling baik saat RH turun di bawah 70%.',
            ];
        }
        if ($dampAir || $rh >= 82) {
            return [
                'verdict' => 'malam',
                'label'   => 'Waktu terbaik: malam hari (19:00 - 05:00)',
                'reason'  => 'RH sekarang ' . self::num($rh) . ' % masih tinggi. Malam hari RH relatif lebih rendah sehingga penguapan lebih efektif.',
            ];
        }
        if ($rh <= 60 && $temp >= $tMin && $status === self::OPTIMAL) {
            return [
                'verdict' => 'sekarang',
                'label'   => 'Waktu terbaik: sekarang (' . date('H:i') . ', RH ' . self::num($rh) . ' %)',
                'reason'  => 'RH rendah dan suhu ruang pengeringan sudah di atas ' . self::num($tMin) . ' C. Ini jam terbaik untuk pengeringan.',
            ];
        }
        if ($status === self::PEMANASAN) {
            return [
                'verdict' => 'tunggu',
                'label'   => 'Tunggu suhu mencapai ' . self::num($tMin) . ' C',
                'reason'  => 'Pengeringan baru efisien setelah ruang pengeringan hangat. Pemanasan sedang berjalan.',
            ];
        }
        return [
            'verdict' => 'layak',
            'label'   => 'Waktu pengeringan layak dijalankan',
            'reason'  => 'Kondisi air dan suhu sudah memenuhi syarat pengeringan.',
        ];
    }

    private static function quality(float $moist, float $temp, float $tOpt, float $tMax, float $tLimit, float $mTarget, float $mStop, int $age): array
    {
        $notes = [];
        $risk  = 'rendah';
        $score = 100.0;

        if ($moist > 20) {
            $risk = 'tinggi';
            $score -= 25;
            $notes[] = 'Kadar air di atas 20% - risiko pertumbuhan jamur (Aspergillus) dan germinasi. Prioritaskan pengeringan.';
        } elseif ($moist > 17) {
            $risk = 'sedang';
            $score -= 10;
            $notes[] = 'Kadar air 17-20% - masih aman, tetapi jangan dibiarkan terlalu lama pada RH tinggi.';
        }

        if ($temp > $tMax) {
            $risk = 'tinggi';
            $score -= 30;
            $notes[] = 'Suhu melewati batas atas - gabah bisa kehilangan bobot, retak, dan menurunkan mutu endosperma.';
        } elseif ($temp > $tOpt + 4) {
            $risk = $risk === 'tinggi' ? 'tinggi' : 'sedang';
            $score -= 8;
            $notes[] = 'Suhu sedikit di atas titik optimal - perhatikan kadar air agar tidak over-drying.';
        }

        if ($moist < 11 && $moist <= $mStop) {
            $score -= 15;
            $notes[] = 'Kadar air < 11% (over-drying) - risiko kehilangan bobot dan menurunkan nilai jual.';
        }

        if ($age > 3600) {
            $notes[] = 'Data sensor sudah lebih dari 1 jam, kesimpulan belum tentu valid.';
        }

        if ($notes === []) {
            $notes[] = 'Kondisi proses normal, tidak ada indikasi penurunan mutu.';
        }

        return [
            'risk'  => $risk,
            'score' => (int) round(self::clamp($score, 0, 100)),
            'notes' => $notes,
        ];
    }

    private static function result(
        string $status, string $label, string $tone, int $score,
        ?float $rate, ?float $eta, ?string $etaAt, float $progress,
        array $conditions, array $summary, array $factors, array $data
    ): array {
        return [
            'status'          => $status,
            'label'           => $label,
            'tone'            => $tone,
            'ready_to_dry'    => in_array($status, [self::OPTIMAL, self::PEMANASAN, self::SELESAI], true),
            'score'           => $score,
            'drying_rate'     => $rate,
            'estimated_hours' => $eta === null ? null : round($eta, 2),
            'eta_at'          => $etaAt,
            'eta_text'        => self::etaText($eta),
            'progress_pct'    => round($progress, 1),
            'conditions'      => $conditions,
            'summary'         => ['tone' => $tone, 'icon' => $summary['icon'] ?? 'sparkles', 'items' => $summary['items'] ?? []],
            'factors'         => $factors,
            'data'            => $data,
        ];
    }

    private static function etaText(?float $eta): ?string
    {
        if ($eta === null) {
            return null;
        }
        if ($eta <= 0.01) {
            return 'Selesai';
        }
        $h = floor($eta);
        $m = (int) round(($eta - $h) * 60);
        if ($h >= 48) {
            $d = (int) floor($h / 24);
            $rh = $h % 24;
            return "{$d} hari " . ($rh > 0 ? "{$rh} jam" : '');
        }
        return $h > 0 ? "{$h} jam" . ($m > 0 ? " {$m} menit" : '') : "{$m} menit";
    }

    private static function ago(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' detik';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . ' menit';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . ' jam';
        }
        return floor($seconds / 86400) . ' hari';
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }

    /** Catat snapshot rekomendasi ke history (dibatasi 1x per 5 menit). */
    public static function log(string $deviceCode, array $result, array $reading): void
    {
        if (!Settings::bool('log_interval')) {
            return;
        }
        $db = Db::pdo();
        $last = $db->prepare('SELECT created_at FROM `drying_logs` WHERE device_code = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$deviceCode]);
        $prev = $last->fetchColumn();
        if ($prev && (time() - strtotime((string) $prev)) < 300) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO `drying_logs`
              (device_code, status, score, dry_rate, est_hours, eta_at, progress_pct, temp_c, moisture_pct, snapshot)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $deviceCode,
            $result['status'],
            $result['score'],
            $result['drying_rate'] ?? 0,
            $result['estimated_hours'],
            $result['eta_at'] ? date('Y-m-d H:i:s', strtotime((string) $result['eta_at'])) : null,
            $result['progress_pct'],
            $reading['temp_c'],
            $reading['moisture_pct'],
            json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function history(string $deviceCode, int $limit = 50): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM `drying_logs` WHERE device_code = ? ORDER BY id DESC LIMIT ' . max(1, min(300, $limit))
        );
        $stmt->execute([$deviceCode]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'at'         => self::iso($r['created_at']),
                'status'     => $r['status'],
                'label'      => self::labelOf((string) $r['status']),
                'score'      => (int) $r['score'],
                'dry_rate'   => (float) $r['dry_rate'],
                'est_hours'  => $r['est_hours'] === null ? null : (float) $r['est_hours'],
                'progress'   => (float) $r['progress_pct'],
                'temp'       => (float) $r['temp_c'],
                'moisture'   => (float) $r['moisture_pct'],
            ];
        }
        return $out;
    }

    public static function labelOf(string $status): string
    {
        return match ($status) {
            self::OPTIMAL   => 'Waktu Pengeringan Optimal',
            self::PEMANASAN => 'Pemanasan - Suhu Belum Cukup',
            self::MENUNGGU  => 'Menunggu Kondisi Layak',
            self::JEDA      => 'Jeda Pengeringan',
            self::SELESAI   => 'Pengeringan Selesai',
            self::BAHAYA    => 'Kondisi Berbahaya',
            self::OFFLINE   => 'Perangkat Offline',
            default          => $status,
        };
    }

    private static function iso(?string $dt): ?string
    {
        if (!$dt) {
            return null;
        }
        $ts = strtotime($dt);
        return $ts ? date('c', $ts) : null;
    }
}
