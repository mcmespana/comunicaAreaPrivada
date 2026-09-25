# Plan 012: El signup comprueba el email con una consulta puntual, no descargando toda la tabla

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-action.php inc/stic-class-6.php`

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

El handler de signup llama a `getAllEmail()`, que hace un `get_entry_list` con query vacía y
`max_results = 0` (sin límite) y carga en memoria TODA la columna de emails del CRM solo para
comprobar si un email ya existe. El coste crece linealmente con el número total de contactos: en una
organización grande son varios MB transferidos por cada intento de alta.

## Current state

- Llamada — `inc/stic-action.php:726` (en `prefix_admin_single_stic_signup`): `getAllEmail()`, luego
  `in_array($fields['email1'], $getAllEmails)` (~:728 y :732).
- Implementación — `inc/stic-class-6.php:338-356`: `get_entry_list` con `query` vacía y
  `max_results => 0`, devolviendo todos los emails.
- **Exemplar de consulta por email** — `getContactByEmail` (`inc/stic-class-6.php:520-540`) ya
  consulta por email con el sub-select de `email_addr_bean_rel`. Reutiliza ese patrón para una
  existencia con `max_results => 1`.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l inc/stic-action.php && php -l inc/stic-class-6.php` | sin errores |

## Scope

**In scope**: `inc/stic-class-6.php` (nuevo método de existencia), `inc/stic-action.php` (usarlo en signup).
**Out of scope**: cambiar la lógica de alta más allá de la comprobación de email duplicado.

## Steps

### Step 1: Método `emailExists` puntual

En `inc/stic-class-6.php`, añade un método que devuelva bool consultando solo por ese email, con
`max_results => 1`, reutilizando el join de `getContactByEmail` (`:520-540`). Escapa el email
(usa `escapeQueryValue` del plan 008 si ya existe; si no, escapa la comilla/backslash aquí):
```php
public function emailExists($email) {
    // mismo módulo/destino que getContactByEmail; query por email exacto; max_results = 1
    // return true si el resultado trae >= 1 entrada
}
```

**Verify**: `php -l inc/stic-class-6.php` → sin errores.

### Step 2: Usar `emailExists` en el signup

En `prefix_admin_single_stic_signup` (`inc/stic-action.php:726-732`), sustituye
`getAllEmail()` + `in_array(...)` por `$objSCP->emailExists($fields['email1'])`. Mantén el mismo
comportamiento (si existe → mensaje de "email ya registrado", no crear).

**Verify**: `grep -n "getAllEmail" inc/stic-action.php` → 0 resultados.

### Step 3 (opcional): marcar `getAllEmail` como obsoleto

Si `getAllEmail` no se usa en ningún otro sitio (`grep -rn "getAllEmail(" inc/ pages/`), añade un
comentario `@deprecated` o elimínalo. Si se usa en otro punto, déjalo.

**Verify**: `grep -rn "getAllEmail(" inc/ pages/` → solo la definición (o ninguna si lo eliminas).

## Test plan

Manual / staging (sin suite; ver 013):
- Signup con un email ya existente → rechazado con el mismo mensaje que antes.
- Signup con un email nuevo → crea el contacto.
- Comprobar (logs) que ya no se descarga la tabla completa de emails en el alta.

## Done criteria

- [ ] `php -l` de ambos archivos exit 0
- [ ] Existe `emailExists($email)` con `max_results => 1` y email escapado
- [ ] `grep -n "getAllEmail" inc/stic-action.php` → 0
- [ ] Fila 012 actualizada en `plans/README.md`

## STOP conditions

- El signup debe comprobar el email tanto en Contacts como en Accounts (módulo "Any"): asegúrate de
  que `emailExists` respeta `getDestinationModule()`; si el comportamiento previo comprobaba ambos,
  replícalo y reporta.
- El sub-select de `getContactByEmail` no encaja para una simple existencia → para y reporta.

## Maintenance notes

- Reviewer: confirmar que la semántica (¿existe este email?) es idéntica a la anterior, incluida la
  sensibilidad a mayúsculas del email.
