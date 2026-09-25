# Plan 006: Los valores del CRM se escapan al pintarlos (XSS almacenado)

> **Executor instructions**: paso a paso, verifica, respeta STOP conditions, actualiza `plans/README.md`.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- inc/stic-listController.php inc/stic-formController.php`

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `bc3c436`, 2026-07-19

## Why this matters

Los valores que vienen del CRM se imprimen en HTML sin escapar en los listados y en los campos de
solo lectura y `<option>`. Un valor con marcado (guardado desde este mismo portal, importado, o por
cualquier actor del lado CRM) se ejecuta como script en el navegador de quien vea el listado/detalle
— robo de sesión/token dentro del área privada. Los `input`/`textarea` editables **ya** se escapan
(`esc_attr`/`esc_textarea`, arreglo SEC-09); faltan estos sumideros de visualización.

## Current state

- Listado — `inc/stic-listController.php:84`:
  ```php
  $html .= "<td " . $tdClass . ">" . $columnValue . "</td>";   // $columnValue del CRM, sin escapar
  ```
- Formulario — `inc/stic-formController.php`:
  - `readOnly` (`:415`): `... > {$defaultValue} </span>` (sin escapar)
  - `info` (`:421`): `... > {$defaultValue}  </span>` (sin escapar)
  - `image` (`:434`): `... > {$value}{$defaultValue}  </span>`
  - `<option>` (`:357` en select y `:403` en multienum):
    `"<option value='" . $skey . "' label='" . $svalue . "' " . $sel . ">" . $svalue . "</option>"`
    (`$skey`/`$svalue` sin escapar)
- **Exentos a propósito** (NO escapar con `esc_html`, ya usan `wp_kses_post` u HTML intencional):
  el tipo `html` y el tipo `note` del motor de formularios. Confírmalo antes de tocar: busca
  `wp_kses_post` en `inc/stic-formController.php`.
- Convención WP: `esc_html()` para texto en nodo, `esc_attr()` para atributos.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l inc/stic-listController.php && php -l inc/stic-formController.php` | sin errores |

## Scope

**In scope**: `inc/stic-listController.php` (celda de tabla), `inc/stic-formController.php` (readOnly,
info, image, option en select y multienum).
**Out of scope**: los tipos `html`/`note` (HTML intencional); los `input`/`textarea` (ya escapados);
la lógica de datos.

## Steps

### Step 1: Escapar la celda de listado

`inc/stic-listController.php:84` → envuelve el valor:
```php
$html .= "<td " . $tdClass . ">" . esc_html((string) $columnValue) . "</td>";
```
Si alguna columna necesita HTML (un enlace de descarga, por ejemplo), comprueba cómo se construye:
si el enlace se arma en el propio controlador con datos de confianza, mantenlo aparte del
`$columnValue` escapado (no escapes el `<a>` que tú generas, sí el texto del CRM que va dentro).

**Verify**: `php -l inc/stic-listController.php` → sin errores. Un listado con un valor `<b>x</b>`
en el CRM se ve como texto literal, no en negrita.

### Step 2: Escapar readOnly / info / image

En `inc/stic-formController.php`:
- `readOnly` (`:415`): `... > " . esc_html((string) ($defaultValue ?? '')) . " </span>`
- `info` (`:421`): igual con `esc_html`.
- `image` (`:434`): escapa la parte de `$defaultValue`; si `$value` es HTML que genera el propio
  motor (p. ej. el `<img>` de la foto de perfil), NO lo escapes — sepáralo del `$defaultValue`.
  Revisa qué contiene `$value` aquí antes de tocar.

**Verify**: `php -l inc/stic-formController.php` → sin errores.

### Step 3: Escapar los `<option>`

`:357` y `:403` → escapa clave/valor:
```php
$html .= "<option value='" . esc_attr($skey) . "' label='" . esc_attr($svalue) . "' " . $sel . ">" . esc_html($svalue) . "</option>";
```

**Verify**: `php -l inc/stic-formController.php` → sin errores.

### Step 4: Confirmar que los tipos con HTML intencional siguen intactos

**Verify**: `grep -n "wp_kses_post" inc/stic-formController.php` — los tipos `html`/`note` siguen
usando `wp_kses_post` (no los cambies a `esc_html`).

## Test plan

Manual (sin suite; ver 013):
- Guardar en un campo (vía el propio portal) un valor `"><script>alert(1)</script>` y luego verlo en
  el listado y en el detalle de solo lectura → debe mostrarse como texto, sin ejecutar.
- Un `<option>` con `&`/comillas se renderiza bien y la selección sigue funcionando.
- Un campo tipo `note`/`html` (p. ej. avisos RGPD con `<a>`) sigue mostrando su HTML.

## Done criteria

- [ ] `php -l` de ambos archivos exit 0
- [ ] `inc/stic-listController.php:84` usa `esc_html`
- [ ] readOnly/info/image y ambos `<option>` escapan sus valores del CRM
- [ ] Los tipos `html`/`note` siguen con `wp_kses_post`
- [ ] Fila 006 actualizada en `plans/README.md`

## STOP conditions

- Un valor de columna del listado contiene HTML que DEBE renderizarse (p. ej. un botón de acción):
  no lo escapes ciegamente; separa el HTML de confianza del texto del CRM y reporta el caso.
- `$value` en el tipo `image` resulta ser texto y no HTML (o al revés) → confirma antes de decidir
  qué escapar.

## Maintenance notes

- Reviewer: cualquier nuevo sumidero que imprima datos del CRM debe escapar por defecto; el HTML
  intencional pasa por `wp_kses_post`.
- Este plan es independiente de SEC-02 (inyección en queries, plan 008): son capas distintas
  (salida vs. entrada).
