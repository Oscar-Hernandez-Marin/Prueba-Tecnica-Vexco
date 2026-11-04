<?php
declare(strict_types=1);

namespace ViesSync;

final class Http
{
    public function __construct(
        private Logger $log,
        private int $maxRetries = 5,
        private int $timeout = 30,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?array $json = null): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $res = $this->exec($method, $url, $headers, $json);

            if ($res['error'] !== null) {
                if ($attempt > $this->maxRetries) {
                    throw new HttpException("Error de red en $method $url: {$res['error']}");
                }
                $this->pause($this->backoff($attempt), "error de red ({$res['error']})");
                continue;
            }

            $status = $res['status'];

            if ($status === 429) {
                if (($res['body']['policyName'] ?? '') === 'DAILY') {
                    throw new HttpException('Límite DIARIO de HubSpot agotado (429 DAILY). Reintenta mañana.', true);
                }
                if ($attempt > $this->maxRetries) {
                    throw new HttpException("429 persistente en $method $url tras {$this->maxRetries} reintentos");
                }
                $ra = $res['headers']['retry-after'] ?? null;
                $wait = is_numeric($ra) ? max(1.0, (float) $ra) : $this->backoff($attempt);
                $this->pause($wait, 'HTTP 429');
                continue;
            }

            if ($status >= 500 && $attempt <= $this->maxRetries) {
                $this->pause($this->backoff($attempt), "HTTP $status");
                continue;
            }

            unset($res['error']);
            return $res;
        }
    }

    private function backoff(int $attempt): float
    {
        return min(60.0, (float) (2 ** $attempt)) + mt_rand(0, 1000) / 1000;
    }

    private function pause(float $seconds, string $why): void
    {
        $this->log->warn(sprintf('%s: espero %.1f s antes de reintentar', $why, $seconds));
        usleep((int) ($seconds * 1_000_000));
    }

    private function exec(string $method, string $url, array $headers, ?array $json): array
    {
        $respHeaders = [];
        $h = array_merge(['Accept: application/json'], $headers);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        if ($json !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);

        $raw = curl_exec($ch);
        $error = $raw === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $body = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : null;
        }

        return [
            'status' => $status,
            'headers' => $respHeaders,
            'body' => $body,
            'raw' => is_string($raw) ? $raw : '',
            'error' => $error,
        ];
    }
}
