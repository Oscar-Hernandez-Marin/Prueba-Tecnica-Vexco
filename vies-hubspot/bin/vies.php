#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ViesSync\{Config, HttpException, HubSpot, Http, Logger, Runner, Vies};

const USAGE = <<<TXT
Uso: php bin/vies.php <comando> [opciones]

Comandos:
  setup               Crea (si no existen) el grupo y las 3 propiedades en Companies. Idempotente.
  run                 Recorre las empresas con nif_cif_vat, valida en VIES y guarda el resultado.

Opciones de "run":
  --dry-run           No escribe en HubSpot; solo muestra qué haría.
  --force             Revalida todo, aunque ya tenga estado final y el NIF no haya cambiado.
  --limit=N           Procesa como máximo N empresas (útil para pruebas).

TXT;

$cmd = $argv[1] ?? 'help';
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
if (!in_array($cmd, ['setup', 'run'], true)) {
    fwrite(STDOUT, USAGE);
    exit($cmd === 'help' ? 0 : 2);
}

try {
    $cfg = Config::load(dirname(__DIR__));
} catch (RuntimeException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$log = new Logger($cfg->logDir);
$hub = new HubSpot(new Http($log, maxRetries: 5), $cfg);
$exit = 0;
$runner = null;

try {
    if ($cmd === 'setup') {
        if (!$hub->propertyExists($cfg->sourceProp)) {
            throw new HttpException("No existe la propiedad de origen {$cfg->sourceProp} en Companies.", true);
        }
        $log->info(($hub->ensureGroup($cfg->groupName(), $cfg->groupLabel()) ? 'Grupo creado: ' : 'Grupo ya existía: ') . $cfg->groupName());
        foreach ($cfg->propertyDefs() as $name => $label) {
            $log->info(($hub->ensureProperty($name, $label, $cfg->groupName()) ? 'Propiedad creada: ' : 'Propiedad ya existía: ') . $name);
        }
    } else {
        $vies = new Vies(new Http($log, maxRetries: 3), $cfg, $log);
        $runner = new Runner($cfg, $hub, $vies, $log);
        $runner->run($opts);
    }
} catch (HttpException $e) {
    $log->error($e->getMessage());
    $exit = 1;
} catch (Throwable $e) {
    $log->error(get_class($e) . ': ' . $e->getMessage());
    $exit = 1;
} finally {
    if ($runner !== null) {
        $log->raw($runner->summary()); // el resumen sale siempre, incluso si la ejecución se corta
    }
    $log->info('Log: ' . $log->path());
}
exit($exit);
