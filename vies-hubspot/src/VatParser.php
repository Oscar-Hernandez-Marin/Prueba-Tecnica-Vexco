<?php
declare(strict_types=1);

namespace ViesSync;
final class VatParser
{
    public const FORMATS = [
        'AT' => 'U[0-9]{8}',
        'BE' => '[01][0-9]{9}',
        'BG' => '[0-9]{9,10}',
        'CY' => '[0-9]{8}[A-Z]',
        'CZ' => '[0-9]{8,10}',
        'DE' => '[0-9]{9}',
        'DK' => '[0-9]{8}',
        'EE' => '[0-9]{9}',
        'EL' => '[0-9]{9}',
        'ES' => '[A-Z0-9][0-9]{7}[A-Z0-9]',
        'FI' => '[0-9]{8}',
        'FR' => '[A-Z0-9]{2}[0-9]{9}',
        'HR' => '[0-9]{11}',
        'HU' => '[0-9]{8}',
        'IE' => '[0-9][A-Z0-9+*][0-9]{5}[A-Z]{1,2}',
        'IT' => '[0-9]{11}',
        'LT' => '([0-9]{9}|[0-9]{12})',
        'LU' => '[0-9]{8}',
        'LV' => '[0-9]{11}',
        'MT' => '[0-9]{8}',
        'NL' => '[0-9]{9}B[0-9]{2}',
        'PL' => '[0-9]{10}',
        'PT' => '[0-9]{9}',
        'RO' => '[0-9]{2,10}',
        'SE' => '[0-9]{12}',
        'SI' => '[0-9]{8}',
        'SK' => '[0-9]{10}',
        'XI' => '([0-9]{9}|[0-9]{12}|GD[0-9]{3}|HA[0-9]{3})',
    ];

    public static function parse(?string $raw): array
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return self::omit('Campo nif_cif_vat vacío');
        }

        $clean = preg_replace('/[\s.\-\/_,]/u', '', $value);
        if ($clean === null) {
            return self::omit('Codificación de caracteres no válida');
        }
        $clean = strtoupper($clean);
        if ($clean === '') {
            return self::omit('Sin caracteres alfanuméricos');
        }
        if (!preg_match('/^[A-Z0-9+*]+$/', $clean)) {
            return self::omit('Contiene caracteres no válidos');
        }

        if (preg_match('/^([A-Z]{2})(.*)$/', $clean, $m)) {
            $prefix = $m[1];
            $number = $m[2];
            $cc = $prefix === 'GR' ? 'EL' : $prefix; 
            if (!isset(self::FORMATS[$cc]) || !preg_match('/[0-9]/', $number)) {
                return self::omit("Prefijo «{$prefix}» no es un Estado miembro cubierto por VIES (país fuera de la UE o formato irreconocible)");
            }
        } elseif (preg_match('/^' . self::FORMATS['ES'] . '$/', $clean)) {
            $cc = 'ES';
            $number = $clean;
        } else {
            return self::omit('Sin prefijo de país y no reconocible como NIF/CIF/NIE español');
        }

        if (!preg_match('/^' . self::FORMATS[$cc] . '$/', $number)) {
            return ['status' => 'invalid', 'reason' => "Formato incorrecto para $cc: «{$number}»"];
        }

        return ['status' => 'ok', 'cc' => $cc, 'number' => $number, 'full' => $cc . $number];
    }

    private static function omit(string $reason): array
    {
        return ['status' => 'omit', 'reason' => $reason];
    }
}
