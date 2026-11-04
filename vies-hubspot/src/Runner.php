<?php
declare(strict_types=1);

namespace ViesSync;

final class Runner
{
    private array $cache = [];
    private array $countryDown = [];

    private array $stats = [
        'leidas' => 0, 'con_nif' => 0, 'ya_al_dia' => 0, 'procesadas' => 0,
        'VALIDO' => 0, 'INVALIDO' => 0, 'ERROR_TEMPORAL' => 0, 'OMITIDO' => 0,
        'fallos_escritura' => 0,
    ];
    private bool $dryRun = false;

    public function __construct(
        private Config $cfg,
        private HubSpot $hub,
        private Vies $vies,
        private Logger $log,
    ) {
    }

    public function run(array $opts): void
    {
        $this->dryRun = (bool) ($opts['dry-run'] ?? false);
        $force = (bool) ($opts['force'] ?? false);
        $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;

        if (!$this->hub->propertyExists($this->cfg->estadoProp())) {
            throw new HttpException("La propiedad {$this->cfg->estadoProp()} no existe. Ejecuta antes: php bin/vies.php setup", true);
        }

        $src = $this->cfg->sourceProp;
        $props = [$src, $this->cfg->estadoProp(), $this->cfg->fechaProp(), $this->cfg->motivoProp()];
        $this->log->info('Inicio' . ($this->dryRun ? ' (DRY-RUN: no se escribe en HubSpot)' : '') . ($force ? ' (--force)' : ''));

        $after = null;
        do {
            [$companies, $after] = $this->hub->listCompanies($after, $props);
            foreach ($companies as $company) {
                $this->stats['leidas']++;
                $raw = $company['properties'][$src] ?? null;
                if ($raw === null) {
                    continue;
                }
                $this->stats['con_nif']++;

                if (!$force && !$this->needsWork($company)) {
                    $this->stats['ya_al_dia']++;
                    continue;
                }
                if ($limit !== null && $this->stats['procesadas'] >= $limit) {
                    $this->log->info("Alcanzado --limit=$limit");
                    break 2;
                }
                $this->processOne($company, (string) $raw);
            }
        } while ($after !== null);

        $this->log->info('Fin del recorrido');
    }

    private function needsWork(array $company): bool
    {
        $p = $company['properties'] ?? [];
        $estado = (string) ($p[$this->cfg->estadoProp()] ?? '');
        if (!in_array($estado, Config::ESTADOS_FINALES, true)) {
            return true;
        }
        $fecha = strtotime((string) ($p[$this->cfg->fechaProp()] ?? ''));
        if ($fecha === false) {
            return true;
        }
        $lastChange = 0;
        foreach ($company['propertiesWithHistory'][$this->cfg->sourceProp] ?? [] as $version) {
            $ts = strtotime((string) ($version['timestamp'] ?? ''));
            if ($ts !== false) {
                $lastChange = max($lastChange, $ts);
            }
        }
        return $lastChange > $fecha;
    }

    private function processOne(array $company, string $raw): void
    {
        $id = (string) $company['id'];
        $parsed = VatParser::parse($raw);

        [$estado, $motivo] = match ($parsed['status']) {
            'omit' => ['OMITIDO', $parsed['reason']],
            'invalid' => ['INVALIDO', $parsed['reason']],
            default => $this->validate($parsed),
        };

        $this->stats['procesadas']++;
        $this->stats[$estado]++;
        $this->log->info(sprintf('empresa=%s nif="%s" => %s%s', $id, $raw, $estado, $motivo !== '' ? " ($motivo)" : ''));

        if ($this->dryRun) {
            return;
        }
        try {
            $this->hub->updateCompany($id, [
                $this->cfg->estadoProp() => $estado,
                $this->cfg->fechaProp() => gmdate('Y-m-d\TH:i:s\Z'),
                $this->cfg->motivoProp() => substr(str_replace(["\r", "\n"], ' ', $motivo), 0, 500),
            ]);
        } catch (HttpException $e) {
            if ($e->fatal) {
                throw $e;
            }
            $this->stats['fallos_escritura']++;
            $this->log->error("No se pudo guardar la empresa $id: " . $e->getMessage());
        }
    }

    private function validate(array $parsed): array
    {
        $full = $parsed['full'];
        $cc = $parsed['cc'];

        if (isset($this->cache[$full])) {
            return $this->cache[$full];
        }
        if (isset($this->countryDown[$cc])) {
            return ['ERROR_TEMPORAL', "VIES no disponible para $cc en esta ejecución: {$this->countryDown[$cc]}"];
        }

        if ($this->cfg->viesDelayMs > 0) {
            usleep($this->cfg->viesDelayMs * 1000);
        }
        $res = $this->vies->check($cc, $parsed['number']);

        switch ($res['result']) {
            case 'VALID':
                return $this->cache[$full] = ['VALIDO', ''];
            case 'INVALID':
                return $this->cache[$full] = ['INVALIDO', $res['detail']];
            default:
                if ($res['down']) {
                    $this->countryDown[$cc] = $res['detail'];
                }
                return ['ERROR_TEMPORAL', $res['detail']];
        }
    }

    public function summary(): string
    {
        $s = $this->stats;
        $lines = [
            '',
            '================ RESUMEN ================',
            sprintf('Empresas leídas de HubSpot ........ %d', $s['leidas']),
            sprintf('Con nif_cif_vat informado ......... %d', $s['con_nif']),
            sprintf('Ya al día (sin trabajo) ........... %d', $s['ya_al_dia']),
            sprintf('Procesadas en esta ejecución ...... %d', $s['procesadas']),
            sprintf('  VALIDO .......................... %d', $s['VALIDO']),
            sprintf('  INVALIDO ........................ %d', $s['INVALIDO']),
            sprintf('  ERROR_TEMPORAL (reintentable) ... %d', $s['ERROR_TEMPORAL']),
            sprintf('  OMITIDO ......................... %d', $s['OMITIDO']),
            sprintf('Fallos al guardar en HubSpot ...... %d', $s['fallos_escritura']),
        ];
        if ($this->dryRun) {
            $lines[] = '(dry-run: no se ha escrito nada en HubSpot)';
        }
        if ($s['ERROR_TEMPORAL'] > 0 || $s['fallos_escritura'] > 0) {
            $lines[] = 'Hay pendientes: vuelve a ejecutar "run"; solo se reprocesarán esos casos.';
        }
        $lines[] = '=========================================';
        return implode(PHP_EOL, $lines);
    }
}
