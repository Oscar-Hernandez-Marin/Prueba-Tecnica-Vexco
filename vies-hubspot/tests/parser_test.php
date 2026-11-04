<?php
declare(strict_types=1);

// Pruebas sin red del normalizador:  php tests/parser_test.php
require __DIR__ . '/../src/autoload.php';

use ViesSync\VatParser;

$cases = [
    // [entrada, status esperado, full esperado|null]
    ['',                    'omit',    null],
    ['   ',                 'omit',    null],
    ['---',                 'omit',    null],
    ['ESB12345678',         'ok',      'ESB12345678'],
    ['es b-123.456 78',     'ok',      'ESB12345678'],
    ['B12345678',           'ok',      'ESB12345678'],  // sin prefijo: se asume ES
    ['12345678Z',           'ok',      'ES12345678Z'],
    ['X1234567L',           'ok',      'ESX1234567L'],  // NIE
    ['GR123456789',         'ok',      'EL123456789'],  // GR -> EL
    ['DE123456789',         'ok',      'DE123456789'],
    ['FRXX123456789',       'ok',      'FRXX123456789'],
    ['NL123456789B01',      'ok',      'NL123456789B01'],
    ['GB123456789',         'omit',    null],           // fuera de la UE
    ['CHE-123.456.789',     'omit',    null],
    ['PENDIENTE',           'omit',    null],
    ['123',                 'omit',    null],
    ['ESB1234',             'invalid', null],           // país conocido, formato imposible
    ['DE12345',             'invalid', null],
    ['ESB12345678€',        'omit',    null],
];

$fail = 0;
foreach ($cases as [$in, $status, $full]) {
    $r = VatParser::parse($in);
    $okStatus = $r['status'] === $status;
    $okFull = $full === null || ($r['full'] ?? null) === $full;
    if (!$okStatus || !$okFull) {
        $fail++;
        echo "FAIL  \"$in\" => " . json_encode($r, JSON_UNESCAPED_UNICODE) . " (esperado $status" . ($full ? " $full" : '') . ")\n";
    }
}
echo $fail === 0 ? "OK: " . count($cases) . " casos\n" : "$fail fallo(s)\n";
exit($fail === 0 ? 0 : 1);
