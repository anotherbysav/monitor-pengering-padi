<?php
/**
 * ============================================================================
 *  ModbusServer - kerangka Modbus TCP (FC03, FC06, FC16) sebagai jembatan
 *  antara modul IoT Modbus dan database.
 * ============================================================================
 */

declare(strict_types=1);

namespace App;

final class ModbusServer
{
    public const REG_SUHU          = 0;   // 40001 float32 (2 reg)
    public const REG_MOISTURE      = 2;   // 40003 float32
    public const REG_AMBIENT_RH    = 4;   // 40005 float32
    public const REG_AMBIENT_TEMP  = 6;   // 40007 float32
    public const REG_HEATER_STATE  = 8;   // 40009 uint16
    public const REG_HEATER_DUTY   = 9;   // 40010 uint16
    public const REG_FAN_STATE     = 10;  // 40011 uint16
    public const REG_FAN_DUTY      = 11;  // 40012 uint16
    public const REG_TEMP_MIN      = 12;  // 40013 float32
    public const REG_TEMP_MAX      = 14;  // 40015 float32
    public const REG_MOIST_TARGET  = 16;  // 40017 float32
    public const REG_NEW_DATA      = 18;  // 40019 uint16 flag

    private const UNIT_ID = 1;

    private $socket;
    private string $host;
    private int $port;
    private string $deviceCode;
    private $log;

    private array $registers = [];
    private bool $hasData = false;

    public function __construct(string $host, int $port, string $deviceCode, ?callable $logger = null)
    {
        $this->host       = $host;
        $this->port       = $port;
        $this->deviceCode = $deviceCode;
        $this->log        = $logger ?? static fn(string $m) => null;
    }

    public function run(): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$this->socket) {
            ($this->log)("GAGAL membuka port {$this->port}: {$errstr} ($errno)");
            exit(1);
        }

        stream_set_blocking($this->socket, false);
        $this->refreshRegisters();

        while (true) {
            $client = @stream_socket_accept($this->socket, 1);
            if ($client === false) {
                $this->refreshRegisters();
                continue;
            }
            ($this->log)("Perangkat terhubung.");
            stream_set_timeout($client, 5);
            $this->handleClient($client);
            fclose($client);
            ($this->log)("Perangkat terputus.");
        }
    }

    private function handleClient($client): void
    {
        $buffer = '';
        while (!feof($client)) {
            $chunk = @fread($client, 512);
            if ($chunk === false || $chunk === '') {
                if (feof($client)) {
                    break;
                }
                usleep(50000);
                continue;
            }
            $buffer .= $chunk;
            $this->refreshRegisters();

            while (strlen($buffer) >= 8) {
                $tid     = self::u16($buffer, 0);
                $proto   = self::u16($buffer, 2);
                $len     = self::u16($buffer, 4);
                $unit    = ord($buffer[6]);
                $func    = ord($buffer[7]);

                if ($proto !== 0) {
                    ($this->log)("Protokol tidak didukung: {$proto}");
                    return;
                }
                if (strlen($buffer) < 6 + $len) {
                    break; // belum lengkap
                }

                $pdu     = substr($buffer, 8, $len - 1);
                $response = $this->handlePdu($func, $pdu, $tid, $unit);
                $buffer  = substr($buffer, 6 + $len);

                if ($response !== null) {
                    fwrite($client, $response);
                }
            }
        }
    }

    private function handlePdu(int $func, string $pdu, int $tid, int $unit): ?string
    {
        // FC03: Read Holding Registers
        if ($func === 3) {
            if (strlen($pdu) < 4) {
                return $this->exception($tid, $unit, $func, 2);
            }
            $start = self::u16($pdu, 0);
            $count = self::u16($pdu, 2);
            if ($count < 1 || $count > 125) {
                return $this->exception($tid, $unit, $func, 3);
            }
            $data = '';
            for ($i = 0; $i < $count; $i++) {
                $data .= pack('n', $this->registers[$start + $i] ?? 0);
            }
            return $this->response($tid, $unit, 3, $data);
        }

        // FC16: Write Multiple Registers (modul IoT mengirim data sensor)
        if ($func === 16) {
            if (strlen($pdu) < 5) {
                return $this->exception($tid, $unit, $func, 2);
            }
            $start = self::u16($pdu, 0);
            $count = self::u16($pdu, 2);
            $byteCount = ord($pdu[4]);
            $payload = substr($pdu, 5, $byteCount);

            for ($i = 0; $i < $count; $i++) {
                $this->registers[$start + $i] = self::u16($payload, $i * 2);
            }
            $this->processWrittenRegisters($start, $count);
            return $this->response($tid, $unit, 16, pack('nn', $start, $count));
        }

        // FC06: Write Single Register
        if ($func === 6) {
            $reg  = self::u16($pdu, 0);
            $val  = self::u16($pdu, 2);
            $this->registers[$reg] = $val;
            $this->processWrittenRegisters($reg, 1);
            return $this->response($tid, $unit, 6, pack('nn', $reg, $val));
        }

        return $this->exception($tid, $unit, $func, 1); // unsupported function
    }

    /** Simpan data dari register perangkat ke database bila ada yang berubah. */
    private function processWrittenRegisters(int $start, int $count): void
    {
        $range = range($start, $start + $count - 1);
        $touchTemp = array_intersect($range, [self::REG_SUHU, self::REG_SUHU + 1]);
        $touchMois = array_intersect($range, [self::REG_MOISTURE, self::REG_MOISTURE + 1]);

        if ($touchTemp === [] || $touchMois === []) {
            return;
        }

        $temp  = $this->floatAt(self::REG_SUHU);
        $moist = $this->floatAt(self::REG_MOISTURE);

        if ($temp === null || $moist === null) {
            return;
        }

        $auto = AutoControl::tick($this->deviceCode);

        Readings::insert([
            'temp_c'         => $temp,
            'moisture_pct'   => $moist,
            'ambient_temp_c' => $this->floatAt(self::REG_AMBIENT_TEMP),
            'ambient_rh_pct' => $this->floatAt(self::REG_AMBIENT_RH),
            'heater_on'      => ($this->registers[self::REG_HEATER_STATE] ?? 0) > 0,
            'heater_duty'    => $this->registers[self::REG_HEATER_DUTY] ?? 0,
            'fan_on'         => ($this->registers[self::REG_FAN_STATE] ?? 0) > 0,
            'fan_duty'       => $this->registers[self::REG_FAN_DUTY] ?? 0,
            'source'         => 'modbus',
        ], $this->deviceCode);

        AutoControl::watchAlerts($this->deviceCode);
        $this->hasData = true;

        $reading = Readings::latest($this->deviceCode);
        $advisor = DryingAdvisor::evaluate($reading, Profile::active(), Actuators::state($this->deviceCode), [
            'age_seconds' => 0,
            'ambient_rh'  => $this->floatAt(self::REG_AMBIENT_RH),
            'ambient_temp'=> $this->floatAt(self::REG_AMBIENT_TEMP),
            'trend'       => Readings::trend($this->deviceCode, 30),
        ]);
        DryingAdvisor::log($this->deviceCode, $advisor, (array) $reading);

        ($this->log)(sprintf(
            'Data diterima: %.2f C / %.2f %%  -> %s (ETA %s, laju %.2f %%/jam) auto:%s',
            $temp,
            $moist,
            $advisor['label'],
            $advisor['eta_text'] ?? '-',
            $advisor['drying_rate'] ?? 0,
            implode(',', $auto['actions'] ?? []) ?: '-'
        ));
    }

    /** Muat register dari state terbaru (perintah aktuator + batas). */
    private function refreshRegisters(): void
    {
        Settings::flush();
        Profile::flush();

        $state = Actuators::state($this->deviceCode);
        $last  = Readings::latest($this->deviceCode);

        if ($last) {
            $this->setFloat(self::REG_SUHU, (float) $last['temp_c']);
            $this->setFloat(self::REG_MOISTURE, (float) $last['moisture_pct']);
            if ($last['ambient_rh_pct'] !== null) {
                $this->setFloat(self::REG_AMBIENT_RH, (float) $last['ambient_rh_pct']);
            }
            if ($last['ambient_temp_c'] !== null) {
                $this->setFloat(self::REG_AMBIENT_TEMP, (float) $last['ambient_temp_c']);
            }
        }

        $this->registers[self::REG_HEATER_STATE] = $state['heater']['on'] ? 1 : 0;
        $this->registers[self::REG_HEATER_DUTY]  = (int) $state['heater']['duty'];
        $this->registers[self::REG_FAN_STATE]    = $state['fan']['on'] ? 1 : 0;
        $this->registers[self::REG_FAN_DUTY]     = (int) $state['fan']['duty'];

        $this->setFloat(self::REG_TEMP_MIN, Settings::float('temp_min'));
        $this->setFloat(self::REG_TEMP_MAX, Settings::float('temp_max'));
        $this->setFloat(self::REG_MOIST_TARGET, Settings::float('moisture_stop'));

        $this->registers[self::REG_NEW_DATA] = $this->hasData ? 1 : 0;
    }

    private function setFloat(int $offset, float $value): void
    {
        [$hi, $lo] = self::floatToRegs($value);
        $this->registers[$offset]     = $hi;
        $this->registers[$offset + 1] = $lo;
    }

    private function floatAt(int $offset): ?float
    {
        if (!isset($this->registers[$offset], $this->registers[$offset + 1])) {
            return null;
        }
        return self::regsToFloat($this->registers[$offset], $this->registers[$offset + 1]);
    }

    private function response(int $tid, int $unit, int $func, string $data): string
    {
        return pack('nnCCn', $tid, 0, $unit, $func, strlen($data)) . $data;
    }

    private function exception(int $tid, int $unit, int $func, int $code): string
    {
        return pack('nnCCn', $tid, 0, $unit, $func | 0x80, 1) . chr($code);
    }

    /* ---------------- konversi IEEE-754 single <-> 2 register ---------------- */

    /** @return array{0:int,1:int} register tinggi, register rendah */
    public static function floatToRegs(float $value): array
    {
        $bytes = pack('G', $value);                       // float32 big-endian
        return [unpack('n', substr($bytes, 0, 2))[1], unpack('n', substr($bytes, 2, 2))[1]];
    }

    public static function regsToFloat(int $reg1, int $reg2): float
    {
        return (float) (unpack('G', pack('nn', $reg1, $reg2))[1] ?? 0);
    }

    private static function u16(string $data, int $offset): int
    {
        return (ord($data[$offset] ?? "\0") << 8) | ord($data[$offset + 1] ?? "\0");
    }
}
