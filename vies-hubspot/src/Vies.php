<?php
declare(strict_types=1);

namespace ViesSync;

final class Vies
{
    private const TRANSIENT = [
        'SERVICE_UNAVAILABLE', 'MS_UNAVAILABLE', 'TIMEOUT', 'SERVER_BUSY',
        'GLOBAL_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ_TIME',
        'MS_MAX_CONCURRENT_REQ', 'MS_MAX_CONCURRENT_REQ_TIME',
    ];
    private const COUNTRY_DOWN = ['MS_UNAVAILABLE', 'TIMEOUT'];

    public function __construct(
        private Http $http,
        private Config $cfg,
        private Logger $log,
        private int $attempts = 3,
    ) {
    }

    public function check(string $cc, string $number): array
    {
        for ($i = 1; ; $i++) {
            $r = $this->once($cc, $number);
            if ($r['result'] !== 'ERROR' || !$r['retry'] || $i >= $this->attempts) {
                unset($r['retry']);
                return $r;
            }
            $wait = 2 ** $i;
            $this->log->warn("VIES {$cc}{$number}: {$r['detail']} (intento $i/{$this->attempts}), espero {$wait} s");
            sleep($wait);
        }
    }
    private function once(string $cc, string $number): array
    {
        try {
            $res = $this->http->request('POST', $this->cfg->viesEndpoint, [], [
                'countryCode' => $cc,
                'vatNumber' => $number,
            ]);
        } catch (HttpException $e) {
            return $this->error('Fallo de comunicación con VIES: ' . $e->getMessage(), false, false);
        }

        $body = $res['body'];

        $codes = [];
        foreach ((array) ($body['errorWrappers'] ?? []) as $w) {
            if (is_array($w) && isset($w['error'])) {
                $codes[] = (string) $w['error'];
            }
        }
        if (($body['actionSucceed'] ?? true) === false && $codes === []) {
            $codes[] = 'UNKNOWN_ERROR';
        }

        if ($codes !== []) {
            if (in_array('INVALID_INPUT', $codes, true)) {
                return ['result' => 'INVALID', 'detail' => 'VIES: INVALID_INPUT (formato no aceptado por el Estado miembro)', 'down' => false, 'retry' => false];
            }
            $transient = array_intersect($codes, self::TRANSIENT) !== [];
            $down = array_intersect($codes, self::COUNTRY_DOWN) !== [];
            return $this->error('VIES: ' . implode(', ', $codes), $transient, $down);
        }

        $valid = $body['valid'] ?? $body['isValid'] ?? null;
        if ($res['status'] === 200 && $valid === true) {
            return ['result' => 'VALID', 'detail' => '', 'down' => false, 'retry' => false];
        }
        if ($res['status'] === 200 && $valid === false) {
            return ['result' => 'INVALID', 'detail' => 'VIES: número no registrado como operador intracomunitario', 'down' => false, 'retry' => false];
        }

        return $this->error("Respuesta inesperada de VIES (HTTP {$res['status']})", $res['status'] >= 500, false);
    }

    private function error(string $detail, bool $retry, bool $down): array
    {
        return ['result' => 'ERROR', 'detail' => $detail, 'down' => $down, 'retry' => $retry];
    }
}
