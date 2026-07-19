# Plan 005: Las redirecciones post-acción no permiten saltar a un host externo

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-action.php`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

Muchos handlers redirigen a `$_REQUEST['scp_current_url']` (un campo oculto controlable por el
cliente) usando `wp_redirect`, que **no valida el host**. Un atacante puede construir un formulario
que envíe `scp_current_url=https://sitio-malicioso` y hacer que, tras la acción, la víctima acabe
en un sitio externo (phishing). El contraste correcto ya existe: `inc/stic-magic-login.php` usa
`wp_safe_redirect`.

## Current state

- `inc/stic-action.php` — redirects que concatenan `scp_current_url` del request y usan
  `wp_redirect`, entre otros en las líneas: 77, 79, 128, 130, 267, 305, 343, 458, 568-577, 674,
  700-702, 908, 995-997. Ejemplo (`:77`):
  ```php
  $redirect_url = $_REQUEST['scp_current_url'] . '&action=detail&id=' . $id . '&msg=true';
  wp_redirect($redirect_url);
  ```
- `wp_safe_redirect` restringe el destino a hosts permitidos (mismo host por defecto) — es un
  reemplazo directo de `wp_redirect` para estas rutas.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l inc/stic-action.php` | `No syntax errors detected` |
| Contar wp_redirect | `grep -c "wp_redirect(" inc/stic-action.php` | baja tras el cambio |

## Scope

**In scope**: `inc/stic-action.php`.
**Out of scope**: `inc/stic-magic-login.php` (ya usa `wp_safe_redirect`); cambiar la forma en que el
front envía `scp_current_url` (los formularios en `pages/*`), salvo que sea necesario (ver Step 2).

## Steps

### Step 1: Sustituir `wp_redirect` por `wp_safe_redirect` en las rutas que usan `scp_current_url`

Cambia cada `wp_redirect($destino)` donde `$destino` derive de `$_REQUEST['scp_current_url']` por
`wp_safe_redirect($destino)`. Mantén el `exit;` que ya sigue (plan de convención §8.9). No cambies
los `wp_redirect(home_url())` de las guardas (ya apuntan a host propio, aunque puedes pasarlos a
`wp_safe_redirect` por consistencia).

**Verify**: `php -l inc/stic-action.php` → sin errores.
**Verify**: `grep -n "wp_redirect(\$redirect_url\|wp_redirect(\$redirectUrl" inc/stic-action.php`
no debe devolver rutas que usen `scp_current_url` (todas migradas a `wp_safe_redirect`).

### Step 2: (Defensa en profundidad) reconstruir la base desde el servidor

Donde sea sencillo, en vez de confiar en `scp_current_url` como base, reconstruye la URL del área
privada desde un valor conocido del servidor (la opción de "Private area URL" del plugin o
`home_url()` + el slug de la página) y añade solo los parámetros (`internalpage`, `action`, `id`,
`msg`). Si esto resulta invasivo en algún handler, quédate solo con el Step 1 para ese handler.

**Verify**: leyendo, ningún redirect construye el host a partir de datos del request sin pasar por
`wp_safe_redirect`.

## Test plan

Manual (sin suite; ver 013):
- Enviar un formulario con `scp_current_url=https://example.org/evil` → tras la acción NO se
  redirige a example.org (wp_safe_redirect lo neutraliza al host propio).
- Los flujos normales (guardar perfil, documento, pago) siguen volviendo a la página correcta con
  su `&msg=…`.

## Done criteria

- [ ] `php -l inc/stic-action.php` exit 0
- [ ] Ningún redirect basado en `scp_current_url` usa `wp_redirect` (todos `wp_safe_redirect`)
- [ ] Los `exit;` posteriores se mantienen
- [ ] Fila 005 actualizada en `plans/README.md`

## STOP conditions

- Algún flujo legítimo necesita redirigir a un host distinto del propio (no debería) → para y reporta.
- `wp_safe_redirect` rompe un redirect que apuntaba a una ruta relativa/rara → revisa que
  `scp_current_url` traiga una URL absoluta del propio sitio; si no, reconstrúyela (Step 2).

## Maintenance notes

- Reviewer: cualquier `wp_redirect` nuevo que use datos del request debe ser `wp_safe_redirect`.
- Si más adelante se centraliza la construcción de URLs de retorno (Step 2 en todos), se elimina la
  dependencia de `scp_current_url` y este riesgo desaparece de raíz.
