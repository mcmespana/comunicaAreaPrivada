# Plan 007: El ID de sesión se regenera al autenticar (anti session-fixation)

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- sinergiacrm-private-area.php inc/stic-magic-login.php`

## Status

- **HECHO** (2026-09-01). Adelantado al resto de los planes de seguridad, que
  quedan pospuestos por estar en periodo de pruebas conceptuales: este es de dos
  líneas, sin riesgo y sin efecto visible, así que no había razón para esperar.
- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

Ninguna de las tres vías de login (contraseña, `?token=`, `?acceso_magico=`) llama a
`session_regenerate_id(true)`. Un atacante que fije/conozca el ID de sesión PHP de la víctima antes
de que ésta se autentique conserva una sesión válida ya autenticada (session fixation). El riesgo
se agrava por la cookie de sesión de larga vida (1 año, deslizante) y su reutilización en la WebView.

## Current state

- **Login por contraseña** — `sinergiacrm-private-area.php`, en
  `sugar_crm_portal_check_user_and_login` (~líneas 607-624): al validar, escribe
  `$_SESSION['scp_user_id']`, `$_SESSION['scp_user_contact_name']`, etc., sin regenerar el id.
- **Token y enlace mágico** — `inc/stic-magic-login.php`, función `sticpa_establish_session`
  (~líneas 191-211): monta `$_SESSION['scp_*']` para ambos flujos sin regenerar el id.
- La sesión PHP se arranca en `init` (`sinergiacrm-private-area.php`,
  `sugar_crm_portal_start_session`, ~línea 902) — para cuando corren los logins ya está iniciada,
  así que `session_regenerate_id(true)` es seguro de llamar.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l sinergiacrm-private-area.php && php -l inc/stic-magic-login.php` | sin errores |

## Scope

**In scope**: `sinergiacrm-private-area.php` (login por contraseña), `inc/stic-magic-login.php`
(`sticpa_establish_session`).
**Out of scope**: la configuración de cookies (ya endurecida: `Secure`/`HttpOnly`/`SameSite`); la
duración de la sesión.

## Steps

### Step 1: Regenerar el id en `sticpa_establish_session`

En `inc/stic-magic-login.php`, dentro de `sticpa_establish_session`, ANTES de escribir cualquier
clave `scp_*`:
```php
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);   // rota el id al autenticar (token / enlace mágico)
}
```
Esto cubre las vías `?token=` y `?acceso_magico=` (ambas pasan por aquí).

**Verify**: `php -l inc/stic-magic-login.php` → sin errores.

### Step 2: Regenerar el id en el login por contraseña

En `sugar_crm_portal_check_user_and_login` (`sinergiacrm-private-area.php`), en el punto en que el
login es correcto y ANTES de escribir `$_SESSION['scp_user_id']`, añade el mismo bloque
`session_regenerate_id(true)`.

**Verify**: `php -l sinergiacrm-private-area.php` → sin errores.
**Verify**: `grep -rn "session_regenerate_id" sinergiacrm-private-area.php inc/stic-magic-login.php`
→ 2 llamadas.

## Test plan

Manual (sin suite; ver 013):
- Anota el valor de la cookie de sesión antes de loguear; tras un login correcto por contraseña, el
  id de sesión debe ser distinto.
- Repite entrando por `?token=` y por `?acceso_magico=`: el id cambia tras montar la sesión.
- La sesión sigue funcionando tras la regeneración (no se pierde el login).

## Done criteria

- [x] `php -l` de ambos archivos exit 0
- [x] `session_regenerate_id(true)` se llama en las tres vías (2 puntos de código: contraseña y
      `sticpa_establish_session`)
- [x] La regeneración ocurre ANTES de escribir las claves `scp_*`
- [x] Fila 007 actualizada en `plans/README.md`

## Resultado (2026-09-01)

Las dos llamadas puestas, cada una con su comentario:

- `inc/stic-magic-login.php:202`, al principio de `sticpa_establish_session` —
  cubre `?token=` y `?acceso_magico=`.
- `sinergiacrm-private-area.php:883`, en `sugar_crm_portal_check_user_and_login`,
  nada más validar y antes de la primera clave `scp_*`.

Las dos van dentro de `if (session_status() === PHP_SESSION_ACTIVE)`, que es
gratis y evita el warning si algún flujo llegara sin sesión abierta.

**La prueba manual del plan sigue pendiente** (comparar la cookie antes y
después de entrar por las tres vías): no hay entorno con WordPress desde aquí.
Lo que sí está cubierto es que la sesión no se pierde al regenerar —los 366
tests pasan, incluidos los de `sticpa_establish_session`— porque
`session_regenerate_id(true)` conserva `$_SESSION` y solo cambia el id.

## STOP conditions

- Tras regenerar, la sesión se pierde inmediatamente (login "no pega") → probablemente la sesión no
  estaba activa en ese punto; confirma el orden respecto a `session_start`/`sugar_crm_portal_start_session`.
- Existe una cuarta vía de autenticación no listada que también escribe `scp_user_id` → cúbrela y reporta.

## Maintenance notes

- Reviewer: cualquier flujo futuro que autentique (escriba `scp_user_id`) debe regenerar el id.
- Complementa, no sustituye, el resto de endurecimientos (cookies seguras ya aplicadas).
