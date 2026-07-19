# Plan 013: Existe una base de verificación (PHPUnit + CRM mockeado) para los flujos críticos

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- . ':!plans'` (este plan añade infraestructura nueva)

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

El plugin no tiene NINGÚN test ni healthcheck, y el único CI (`.github/workflows/deploy-produccion.yml`)
sube por FTPS a producción sin build/lint/test previo. Los flujos de mayor riesgo — login por token,
validación HMAC del enlace mágico, signup, subida de documento, creación de pago — no tienen
cobertura de caracterización, así que cualquier refactor (planes 002, 011, 015) es inverificable.
Esta es la pieza que desbloquea los demás con seguridad.

## Current state

- Sin `composer.json`, `package.json`, `phpunit.xml`, ni archivos de test (búsqueda en el repo).
- El diálogo con el CRM pasa por un único cliente: `SugarRestApiCall` (`inc/stic-class-6.php`),
  instanciado vía `SugarRestApiCall::getObjSCP()`. Es el punto natural para mockear el CRM.
- Lógica pura testeable sin CRM: la validación del enlace mágico (`inc/stic-magic-login.php`, HMAC
  sobre `module|contactId|exp` con `hash_hmac`/`hash_equals`, funciones ~`:104-141`) y el saneo del
  token hex (`:248`). El sanitizado de `sticpa_request_to_module_data` (plan 002) también es puro.
- Las funciones usan APIs de WordPress (`wp_redirect`, `__()`, `get_transient`, `sanitize_*`) — hay
  que stubearlas (Brain Monkey o WP_Mock) o definir stubs mínimos en el bootstrap de tests.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Instalar | `composer install` | exit 0 |
| Tests | `composer test` (alias de `vendor/bin/phpunit`) | todos verdes |
| Lint todo | `find . -name '*.php' -not -path './vendor/*' -not -path './.agents/*' -print0 \| xargs -0 -n1 php -l` | sin errores |

## Scope

**In scope** (crear): `composer.json`, `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/**`,
`.gitignore` (para `vendor/`), y un job `test` en `.github/workflows/deploy-produccion.yml` (o un
workflow `ci.yml` aparte) que corra lint + phpunit y bloquee el deploy si fallan.
**Out of scope**: reescribir el código para hacerlo testeable más allá de introducir un punto de
inyección del cliente CRM si es imprescindible (mínimo cambio, documentado).

## Steps

### Step 1: composer.json + PHPUnit + stubs de WordPress

Crea `composer.json` con `phpunit/phpunit` y una librería de stubs de WP (p. ej. `brain/monkey` o
`10up/wp_mock`) como `require-dev`, y un script `"test": "phpunit"`. Añade `.gitignore` con
`/vendor/`.

**Verify**: `composer install` exit 0; `vendor/bin/phpunit --version` imprime versión.

### Step 2: bootstrap + configuración

`phpunit.xml.dist` apuntando a `tests/` con `tests/bootstrap.php` que cargue el autoload de Composer,
inicialice los stubs de WP y defina constantes mínimas (`HOUR_IN_SECONDS`, etc.) y stubs de las
funciones WP usadas por el código bajo test.

**Verify**: `composer test` corre (aunque sea con 0 tests) sin errores de bootstrap.

### Step 3: Tests de caracterización de la lógica pura (sin CRM)

Escribe tests para lo que no necesita CRM:
- Validación del enlace mágico: firma válida acepta; firma manipulada (otro id / exp mayor) rechaza;
  enlace caducado rechaza; `count($parts) !== 4` rechaza; módulo fuera de la whitelist rechaza.
- Saneo del token hex (`:248`): deja pasar hex, elimina el resto.
- (Si el plan 002 ya existe) `sticpa_request_to_module_data`: excluye `id`/`stic_pa_password_c`/
  `ajmcm_pa_token_c`/`assigned_user_id`/`deleted`; serializa arrays como `^a^,^b^`.

**Verify**: `composer test` → estos tests pasan.

### Step 4: Tests de handlers con el CRM mockeado

Mockea `SugarRestApiCall` (doble de test que devuelva respuestas fijas) para caracterizar:
login por token (contacto encontrado → sesión montada; no encontrado → no), signup (email duplicado
→ rechazo; nuevo → `set_entry` llamado), y la guarda de sesión (plan 001: sin sesión → `wp_redirect`
a home, sin llamar al CRM). Introduce el punto de inyección mínimo necesario para sustituir
`getObjSCP()` por el doble (documenta el cambio).

**Verify**: `composer test` → todos verdes.

### Step 5: Puerta de CI antes del deploy

Añade un job que ejecute `php -l` sobre todos los `.php` del plugin y `composer test`, y haz que el
deploy a `produccion` dependa de que ese job pase.

**Verify**: el workflow define el job y el deploy lo requiere (`needs:` en el job de deploy).

## Test plan

El entregable ES la suite. Cobertura mínima: los 3-5 flujos críticos citados. Modela los tests según
un estilo consistente (un archivo por área: `tests/MagicLinkTest.php`, `tests/SignupTest.php`, etc.).

## Done criteria

- [ ] `composer install` exit 0 y `composer test` verde con ≥ los tests de los Steps 3-4
- [ ] `vendor/` en `.gitignore`
- [ ] CI ejecuta lint + phpunit y bloquea el deploy si fallan
- [ ] Fila 013 actualizada en `plans/README.md`

## STOP conditions

- Hacer testeable un handler exige un refactor grande (no solo inyectar el cliente CRM) → para y
  reporta: caracteriza primero la lógica pura y deja el handler para después.
- No puedes mockear `getObjSCP()` sin tocar mucho código → reporta la mínima interfaz de inyección
  que propondrías antes de aplicarla.

## Maintenance notes

- A partir de aquí, todo cambio en flujos críticos debe venir con test. Los planes 002 y 011 deben
  ejecutarse DESPUÉS de este y añadir sus propios tests.
- Considera un healthcheck del CRM como test de integración opcional (marcado, no en el gate).
