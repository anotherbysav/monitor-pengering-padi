<?php
/**
 * Kontrol actuator (heater & fan) + pengaman (safety interlock).
 *
 * State aktual perangkat diambil dari reading sensor terakhir, sehingga
 * perintah dari web maupun logika auto selalu konsisten dengan kondisi nyata.
 */

declare(strict_types=1);

namespace App;

final class Actuators
{
    public const HEATER = 'heater';
    public const FAN    = 'fan';

    /**
     * State aktual perangkat berdasarkan reading terakhir, ditambah perintah
     * terakhir yang dikirim server (agar UI bisa menampilkan status
     * "menunggu konfirmasi perangkat").
     */
    public static function state(string $deviceCode): array
    {
        $last = Readings::latest($deviceCode);

        $out = [];
        foreach ([self::HEATER => 'Heater', self::FAN => 'Kipas'] as $actuator => $label) {
            $actualOn = (bool) ($last[$actuator . '_on'] ?? false);
            $actualDuty = (int) ($last[$actuator . '_duty'] ?? 0);
            $command = self::lastChange($deviceCode, $actuator);
            $cmdOn = $command === null ? $actualOn : (bool) $command['state'];
            $cmdDuty = $command === null ? $actualDuty : (int) $command['duty'];

            $out[$actuator] = [
                'on'         => $actualOn,
                'duty'       => $actualDuty,
                'commanded'  => $cmdOn,
                'commanded_duty' => $cmdDuty,
                'pending'    => $cmdOn !== $actualOn || ($cmdOn && $cmdDuty !== $actualDuty),
                'source'     => 'device',
                'label'      => $label,
                'updated_at' => $last ? self::iso($last['recorded_at']) : null,
                'last_change' => $command,
            ];
        }

        $out['mode']      = Settings::bool('auto_mode') ? 'auto' : 'manual';
        $out['commander'] = Settings::bool('auto_mode') ? 'auto' : 'user';
        $out['last_seen'] = $last ? self::iso($last['recorded_at']) : null;
        $out['online']    = $last !== null
            && self::ageSeconds($last['recorded_at']) <= max(30, Settings::int('offline_timeout'));

        return $out;
    }

    private static function ageSeconds(string $dt): int
    {
        $ts = strtotime($dt);
        return $ts === false ? PHP_INT_MAX : max(0, time() - $ts);
    }

    /**
     * Perintah yang harus dijalankan perangkat (dipakai endpoint /api/sensor/poll).
     * Pada mode manual berasal dari setelan user, pada mode auto dari
     * keputusan AutoControl terakhir.
     */
    public static function target(string $deviceCode): array
    {
        $state = self::state($deviceCode);
        $auto  = Settings::bool('auto_mode');

        $out = ['mode' => $auto ? 'auto' : 'manual'];
        foreach ([self::HEATER, self::FAN] as $actuator) {
            $dutySetting = $actuator === self::HEATER ? Settings::int('heater_duty') : Settings::int('fan_duty');
            $on = $state[$actuator]['commanded'];
            $duty = (int) $state[$actuator]['commanded_duty'];

            if (!$auto) {
                $on = $actuator === self::HEATER
                    ? Settings::bool('heater_manual')
                    : Settings::bool('fan_manual');
                $duty = $on ? max(10, $dutySetting) : 0;
            } elseif ($duty <= 0 && $on) {
                $duty = $dutySetting;
            }

            $out[$actuator] = ['on' => $on, 'duty' => $on ? max(0, min(100, $duty)) : 0, 'mode' => $out['mode']];
        }

        return $out;
    }

    /**
     * Ubah state satu actuator.
     */
    public static function set(string $deviceCode, string $actuator, bool $on, ?int $duty, string $source, string $reason = ''): array
    {
        if (!in_array($actuator, [self::HEATER, self::FAN], true)) {
            throw new \DomainException('Actuator tidak dikenal: ' . $actuator);
        }

        $current = self::state($deviceCode)[$actuator];
        $newDuty = $duty === null ? (int) $current['commanded_duty'] : max(0, min(100, $duty));
        if ($on && $newDuty <= 0) {
            $newDuty = $actuator === self::FAN ? 60 : 80;
        }
        if (!$on) {
            $newDuty = 0;
        }

        // Safety: jangan nyalakan heater bila suhu sudah di batas atas / keras
        if ($on && $actuator === self::HEATER) {
            $last = Readings::latest($deviceCode);
            $temp = $last ? (float) $last['temp_c'] : null;
            $tMax = Settings::float('temp_max');
            $tLim = Settings::float('temp_limit');
            if ($temp !== null && $temp >= $tLim) {
                throw new \DomainException("Heater ditolak: suhu sudah $temp C (batas keras $tLim C).");
            }
            if ($temp !== null && $temp >= $tMax && in_array($source, ['user', 'web'], true)) {
                throw new \DomainException("Heater ditolak: suhu $temp C sudah mencapai batas atas $tMax C. Turunkan batas atas dulu.");
            }
        }

        // Bandingkan dengan status PERINTAH (commanded), bukan status aktual
        // perangkat. Kalau aktual yang dipakai, perintah baru terkira "tidak
        // ada perubahan" saat perangkat lama/offline sehingga UI tidak
        // bereaksi despite saklar sudah diklik.
        $prevCmdOn = (bool) $current['commanded'];
        $prevCmdDuty = (int) $current['commanded_duty'];

        if ($prevCmdOn === $on && $prevCmdDuty === $newDuty) {
            return ['changed' => false, 'actuator' => $actuator, 'on' => $on, 'duty' => $newDuty];
        }

        self::log($deviceCode, $actuator, $prevCmdOn, $on, $prevCmdDuty, $newDuty, $source, $reason);

        // Perintah manual = dari dashboard ('web') atau operator ('user').
        // Simpan override manual + keluar dari mode auto supaya perintah
        // klik tidak langsung ditimpa controller otomatis.
        if (in_array($source, ['user', 'web'], true)) {
            if ($actuator === self::HEATER) {
                Settings::save(['heater_manual' => $on, 'heater_duty' => $newDuty ?: Settings::int('heater_duty')]);
            } else {
                Settings::save(['fan_manual' => $on, 'fan_duty' => $newDuty ?: Settings::int('fan_duty')]);
            }
            if (Settings::bool('auto_mode')) {
                Settings::save(['auto_mode' => false]);
            }
        }

        return ['changed' => true, 'actuator' => $actuator, 'on' => $on, 'duty' => $newDuty];
    }

    /** Matikan semua (tombol darurat). */
    public static function allOff(string $deviceCode, string $source, string $reason = 'Tombol darurat ditekan'): array
    {
        $results = [];
        foreach ([self::HEATER, self::FAN] as $actuator) {
            $results[$actuator] = self::set($deviceCode, $actuator, false, 0, $source, $reason);
        }
        if (Settings::bool('auto_mode')) {
            Settings::save(['auto_mode' => false, 'heater_manual' => false, 'fan_manual' => false]);
        }
        return $results;
    }

    public static function log(string $deviceCode, string $actuator, bool $prev, bool $new, int $prevDuty, int $newDuty, string $source, string $reason = ''): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO `actuator_logs`
                (device_code, actuator, previous_state, new_state, previous_duty, new_duty, source, reason)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$deviceCode, $actuator, $prev ? 1 : 0, $new ? 1 : 0, $prevDuty, $newDuty, substr($source, 0, 30), mb_substr($reason, 0, 190)]);
    }

    public static function lastChange(string $deviceCode, string $actuator): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM `actuator_logs` WHERE device_code = ? AND actuator = ? ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$deviceCode, $actuator]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'state'  => (bool) $row['new_state'],
            'duty'   => (int) $row['new_duty'],
            'source' => $row['source'],
            'reason' => $row['reason'],
            'at'     => self::iso($row['created_at']),
        ];
    }

    public static function history(string $deviceCode, int $limit = 50): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM `actuator_logs` WHERE device_code = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$deviceCode]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'        => (int) $row['id'],
                'actuator'  => $row['actuator'],
                'label'     => $row['actuator'] === 'heater' ? 'Heater' : 'Kipas',
                'from'      => (bool) $row['previous_state'],
                'to'        => (bool) $row['new_state'],
                'duty'      => (int) $row['new_duty'],
                'source'    => $row['source'],
                'reason'    => $row['reason'],
                'created_at'=> self::iso($row['created_at']),
            ];
        }
        return $out;
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
