# Validación VIES de empresas de HubSpot

Script PHP que recorre las **companies** de HubSpot con la propiedad `nif_cif_vat` informada, valida cada número contra la API REST pública de **VIES** (Comisión Europea) y guarda el resultado en tres propiedades propias de la empresa. No modifica nunca los datos de partida.

## Requisitos

- PHP **8.1+** con las extensiones `curl` y `json` (sin Composer ni dependencias).
- Una **service key** de HubSpot (Development → Keys → Service keys) con estos scopes:
  - `crm.objects.companies.read`
  - `crm.objects.companies.write`
  - `crm.schemas.companies.read`
  - `crm.schemas.companies.write` (solo hace falta para `setup`; se puede quitar después)

## Configuración

```bash
cp .env.example .env
# edita .env: HUBSPOT_TOKEN y EXERCISE_NAME (tu nombre)
```

`EXERCISE_NAME=Ana` genera las propiedades `vies_ana_estado`, `vies_ana_fecha`, `vies_ana_motivo` dentro del grupo `ejercicio_vexco_ana` (etiqueta «Ejercicio Vexco – Ana»).

## Uso

```bash
php bin/vies.php setup              # crea grupo + 3 propiedades (idempotente)
php bin/vies.php run --dry-run --limit=10   # prueba sin escribir en HubSpot
php bin/vies.php run                # ejecución real
php bin/vies.php run                # otra vez: solo reintenta errores y NIF cambiados
php bin/vies.php run --force        # revalida todo
php tests/parser_test.php           # pruebas del normalizador (sin red)
```

Cada ejecución escribe un log en `logs/vies-AAAAmmdd-HHMMSS.log` y termina con un **resumen** (leídas, con NIF, ya al día, procesadas, VALIDO / INVALIDO / ERROR_TEMPORAL / OMITIDO, fallos al guardar).

Para probar sin tocar VIES real, apunta `VIES_ENDPOINT` al servicio de pruebas (ver `.env.example`).

## Estados guardados

| Estado | Cuándo | Motivo |
|---|---|---|
| `VALIDO` | VIES responde `valid: true` | vacío |
| `INVALIDO` | VIES responde `valid: false` o `INVALID_INPUT`; o formato imposible para el país | causa |
| `ERROR_TEMPORAL` | VIES caído, timeout, límite de concurrencia, red… | código de error. Se reintenta en la siguiente ejecución |
| `OMITIDO` | vacío, país fuera de la UE, formato irreconocible | causa |

La fecha (ISO 8601, UTC) se guarda en todos los estados como «fecha del último intento».

## Estructura

```
bin/vies.php        CLI (setup / run)
src/Config.php      .env y nombres derivados
src/Http.php        cURL + reintentos (429, 5xx, red)
src/HubSpot.php     listado paginado, PATCH, creación de propiedades
src/VatParser.php   normalización y reconocimiento de país/formato
src/Vies.php        cliente VIES + clasificación de errores
src/Runner.php      orquestación, idempotencia y resumen
tests/              pruebas del parser
```
