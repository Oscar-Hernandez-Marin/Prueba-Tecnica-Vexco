<?php
declare(strict_types=1);

namespace ViesSync;


final class Config
{
    public const ESTADOS_FINALES = ['VALIDO', 'INVALIDO', 'OMITIDO'];

    public function __construct(
        public readonly string $token,
        public readonly string $nombre,
        public readonly string $slug,
        public readonly string $hubspotBase,
        public readonly string $viesEndpoint,
        public readonly int $viesDelayMs,
        public readonly string $logDir,
        public readonly string $sourceProp = 'nif_cif_vat',
    ) {
    }

    public static function load(string $root): self
    {
        $file = self::readDotEnv($root . '/.env');
        $get = static function (string $key, ?string $default = null) use ($file): ?string {
            $v = getenv($key);
            if ($v !== false && $v !== '') {
                return $v;
            }
            return $file[$key] ?? $default;
        };

        $token = $get('HUBSPOT_TOKEN');
        $nombre = $get('EXERCISE_NAME');
        if (!$token || !$nombre) {
            throw new \RuntimeException('Faltan HUBSPOT_TOKEN y/o EXERCISE_NAME. Copia .env.example a .env y rellénalo.');
        }

        $slug = strtolower(strtr($nombre, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ç' => 'C',
        ]));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', $slug), '_');
        if ($slug === '') {
            throw new \RuntimeException('EXERCISE_NAME debe contener al menos una letra o número.');
        }

        return new self(
            token: $token,
            nombre: $nombre,
            slug: $slug,
            hubspotBase: rtrim((string) $get('HUBSPOT_BASE_URL', 'https://api.hubapi.com'), '/'),
            viesEndpoint: (string) $get('VIES_ENDPOINT', 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number'),
            viesDelayMs: max(0, (int) $get('VIES_DELAY_MS', '300')),
            logDir: $root . '/logs',
        );
    }

    public function estadoProp(): string { return "vies_{$this->slug}_estado"; }
    public function fechaProp(): string { return "vies_{$this->slug}_fecha"; }
    public function motivoProp(): string { return "vies_{$this->slug}_motivo"; }
    public function groupName(): string { return "ejercicio_vexco_{$this->slug}"; }
    public function groupLabel(): string { return "Ejercicio Vexco – {$this->nombre}"; }

    public function propertyDefs(): array
    {
        return [
            $this->estadoProp() => "VIES {$this->nombre} – Estado",
            $this->fechaProp() => "VIES {$this->nombre} – Fecha validación",
            $this->motivoProp() => "VIES {$this->nombre} – Motivo",
        ];
    }

    private static function readDotEnv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim(trim($v), "\"'");
        }
        return $out;
    }
}
