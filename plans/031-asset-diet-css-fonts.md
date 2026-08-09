# Plan 031: Dieta de assets — CSS minificado en el deploy, Inter autoalojada y JS solo donde se usa

> **Executor instructions**: Sigue este plan paso a paso. Los steps son independientes
> entre sí (un STOP en uno no bloquea los demás), SALVO que el step 2 debe completarse
> entero o revertirse entero. Ejecuta cada verificación antes de avanzar. Al terminar,
> actualiza la fila de este plan en `plans/README.md`.
>
> **Drift check (ejecutar primero)**:
> `git diff --stat 337ec6a..HEAD -- .github/workflows/deploy-produccion.yml sinergiacrm-private-area.php inc/stic-magic-login.php css/stic-base.css`
> Ante un desajuste con "Current state", ese step es STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (steps 1, 3) / MED (step 2: tipografía; step 4 opcional: DataTables)
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `337ec6a`, 2026-08-09

## Why this matters

Cada navegación del área es una recarga completa, así que el peso de los assets se paga en
CADA tap, no una vez: el WebView tiene que reconstruir el CSSOM de ~268 KB de CSS sin
minificar (216 KB solo `custom-style.css`, un 27 % son comentarios) antes de pintar. La
tipografía Inter llega de un TERCER origen (Google Fonts) con el CSS render-blocking y los
woff2 encadenados detrás — en el arranque en frío de la app son dos saltos DNS+TLS delante
del primer pintado con la letra correcta, y luego un reflow (FOUT). Y `stic-cropper.js`
(13 KB) se carga en todas las páginas cuando solo 4 tienen `input type=file`.

Nada de esto necesita tocar reglas CSS ni comportamiento: minificado mecánico en el deploy,
fuentes servidas por el propio plugin y un enqueue condicional.

## Current state

### CSS sin minificar y render-blocking

- `css/custom-style.css` — 216 461 bytes, sin minificar; `css/stic-base.css` — 51 906 bytes.
- Encolados en `sugar_crm_portal_style_and_script()` (`sinergiacrm-private-area.php:1168-1212`)
  con versión `filemtime` (`$ver`, `:1176-1179`). No existe ningún paso de build en el repo.
- El deploy es un workflow de GitHub Actions que sube por FTPS
  (`.github/workflows/deploy-produccion.yml`): job `test` (lint + PHPUnit, `:23-42`) y job
  `deploy` (`:44-90`) que hace checkout y sube con `SamKirkland/FTP-Deploy-Action` de forma
  incremental. **El sitio en producción sirve exactamente lo que hay en el checkout del job
  deploy** — por eso minificar ahí es seguro: el repo se queda con los fuentes legibles.

### Tipografía en origen externo

- `sinergiacrm-private-area.php:1189`:
  ```php
  wp_enqueue_style('stic-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', array(), null);
  ```
- Preconnects en `:1153-1165` (`sticpa_font_resource_hints`).
- La pantalla puente pide OTRO conjunto de pesos (`inc/stic-magic-login.php:321-322`):
  ```html
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
  ```
- No hay ningún `@font-face` propio (`grep "@font-face" css/*.css` → 0). El fallback es el
  stack de sistema (definido en `css/custom-style.css`, variable de fuente).
- Los 5 pesos (400-800) están en uso real en el CSS (verificado en una auditoría anterior —
  ver `plans/README.md`, "Findings considered and rejected"): NO recortes pesos.

### JS cargado donde no se usa

- `sinergiacrm-private-area.php:146-147` — `stic-cropper.js` (12 933 B) se encola SIEMPRE:
  ```php
  wp_register_script('stic-cropper', plugin_dir_url(__FILE__) . 'js/stic-cropper.js', array(), $jsver('js/stic-cropper.js'), true);
  wp_enqueue_script('stic-cropper');
  ```
- Páginas con `input type=file` (censo verificado por grep): `pages/single_stic_documents.php`,
  `pages/single_stic_comunica_monitor.php`, `pages/single_stic_comunica_perfil.php`,
  `pages/single_stic_profile.php`. Ninguna otra.
- El patrón de enqueue condicional YA existe en la misma función (`dcms_insertar_js`,
  `:94-171`): mira el bloque de `$ibanPages` (`:100-111`) — copia ese estilo.

### DataTables aporta solo una caja de búsqueda (contexto para el step 4 OPCIONAL)

- Los 11 listados usan `'paging' => false` y `inc/stic-listController.php:37-39` fuerza
  `'ordering' => false`. `pages/list_stic_contacts.php:26` además `'searching' => false`:
  ahí DataTables se inicializa PARA NADA.
- Coste: `js/jquery.dataTables.min.js` (90 265 B) + `css/vendor/jquery.dataTables.min.css`
  (18 369 B) + dependencia jQuery, y un rebuild del DOM de la tabla tras el primer pintado
  (`js/stic-init.js`, init dirigida por `data-dt-settings`).

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint PHP | `php -l sinergiacrm-private-area.php && php -l inc/stic-magic-login.php` | sin errores |
| Validar workflow | el YAML es válido si GitHub lo acepta; localmente `python3 -c "import yaml,sys;yaml.safe_load(open('.github/workflows/deploy-produccion.yml'))"` | exit 0 |
| Probar el minificado localmente | `npx esbuild css/custom-style.css --minify --outfile=/tmp/custom-style.min.css` | exit 0; tamaño resultante < 150 KB |
| Tests (con vendor/) | `composer test` | verdes |

## Scope

**In scope**:
- `.github/workflows/deploy-produccion.yml` (step 1)
- `fonts/` (carpeta nueva con los woff2), `css/stic-base.css` (solo AÑADIR los `@font-face`),
  `sinergiacrm-private-area.php` (quitar enqueue de Google Fonts y preconnects; enqueue
  condicional del cropper), `inc/stic-magic-login.php` (solo las 2 líneas de fonts) (steps 2-3)
- Step 4 (opcional): `js/stic-init.js`, `sinergiacrm-private-area.php` (enqueue DataTables),
  `pages/list_stic_contacts.php`

**Out de scope** (NO tocar):
- El CONTENIDO de las reglas de `css/custom-style.css` / `css/stic-base.css` — la
  consolidación de duplicados y `!important` es el plan 018 (PARCIAL, con runbook de QA
  visual propio). Aquí solo minificado mecánico EN EL DEPLOY, nunca en el repo.
- `js/fullcalendar/**`, `selectize.min.js`, `iban.js` (vendorizados).
- El orden de la cascada de estilos (`stic-base` → librerías → `custom-style`).

## Git workflow

- Rama: la del operador; si no, `advisor/031-asset-diet`.
- Un commit por step. Ej.: `perf(assets): minificado de CSS/JS propio en el deploy`.
- NO push/PR salvo instrucción.

## Steps

### Step 1: Minificar CSS y JS propios en el job de deploy

En `.github/workflows/deploy-produccion.yml`, job `deploy`, AÑADE entre el checkout (`:49-50`)
y el paso de FTPS (`:52`) estos dos pasos:

```yaml
      - name: Preparar Node (para minificar assets)
        uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Minificar CSS y JS propios (solo en el artefacto desplegado)
        run: |
          npx --yes esbuild css/custom-style.css --minify --allow-overwrite --outfile=css/custom-style.css
          npx --yes esbuild css/stic-base.css   --minify --allow-overwrite --outfile=css/stic-base.css
          npx --yes esbuild js/stic-ui.js       --minify --allow-overwrite --outfile=js/stic-ui.js
          npx --yes esbuild js/stic-utils.js    --minify --allow-overwrite --outfile=js/stic-utils.js
          npx --yes esbuild js/stic-init.js     --minify --allow-overwrite --outfile=js/stic-init.js
          npx --yes esbuild js/stic-cropper.js  --minify --allow-overwrite --outfile=js/stic-cropper.js
```

Puntos clave:
- Se minifica EN EL CHECKOUT DEL RUNNER, justo antes de subir: el repo no cambia, producción
  recibe el minificado. El versionado por `filemtime` del enqueue sigue funcionando (el
  checkout regenera mtimes).
- esbuild minifica CSS y JS sin configuración y es un solo binario vía npx. NO uses
  `--bundle` (los archivos son standalone).
- NO minifiques `css/selectize.css` ni nada de `css/vendor/` o `js/fullcalendar/` (ya
  minificados o vendorizados).

**Verify** (local): `npx --yes esbuild css/custom-style.css --minify --outfile=/tmp/c.min.css && wc -c < /tmp/c.min.css` → un número claramente menor que 216461 (esperable ~140-150k).
**Verify**: `python3 -c "import yaml;yaml.safe_load(open('.github/workflows/deploy-produccion.yml'))"` → exit 0.
**Verify**: `git diff --stat` NO muestra cambios en `css/` ni `js/` (el repo queda intacto).

### Step 2: Autoalojar Inter (los 5 pesos, subset latin)

**Requiere red. Si no tienes acceso de red, este step entero es STOP (repórtalo y sigue con el 3).**

1. Descarga los woff2 (subset latin) de los pesos 400, 500, 600, 700 y 800. Método: pide el
   CSS a Google con un User-Agent moderno y extrae las URLs `latin` (el bloque marcado
   `/* latin */`):
   ```bash
   mkdir -p fonts
   curl -sS -A "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125 Safari/537.36" \
     "https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" -o /tmp/inter.css
   # Para cada peso: localiza el bloque "/* latin */" de ese peso en /tmp/inter.css,
   # copia su URL https://fonts.gstatic.com/.../*.woff2 y descárgala como fonts/inter-<peso>.woff2
   ```
   Resultado esperado: `fonts/inter-400.woff2 … fonts/inter-800.woff2` (~15-25 KB cada uno).
   Los archivos SÍ se commitean (el deploy FTPS los subirá como cualquier otro asset; no
   están en la lista `exclude` del workflow).
2. Añade al PRINCIPIO de `css/stic-base.css` los `@font-face` (5 bloques, uno por peso):
   ```css
   /* Inter autoalojada (plan 031): antes venía de fonts.googleapis.com, un
      tercer origen render-blocking en cada arranque en frío de la app. */
   @font-face {
     font-family: 'Inter';
     font-style: normal;
     font-weight: 400;
     font-display: swap;
     src: url('../fonts/inter-400.woff2') format('woff2');
     unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0300-0301, U+0303-0304, U+0308-0309, U+0323, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
   }
   /* …repetir con font-weight 500/600/700/800 y su fichero… */
   ```
   (La ruta es relativa al CSS: `css/stic-base.css` → `../fonts/`.)
3. En `sinergiacrm-private-area.php`:
   - Elimina la línea `:1189` (`wp_enqueue_style('stic-google-fonts', ...)`).
   - Elimina el filtro completo `sticpa_font_resource_hints` (`:1153-1165`) y su
     `add_filter` (`:1153`) — ya no hay origen externo al que preconectar.
   - Añade un preload del peso del cuerpo, dentro del mismo `if` de shortcode de
     `sugar_crm_portal_style_and_script()`:
     ```php
     add_action('wp_head', function () {
         global $post;
         if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sinergiacrm-private-area')) {
             echo "<link rel='preload' href='" . esc_url(plugins_url('fonts/inter-400.woff2', __FILE__)) . "' as='font' type='font/woff2' crossorigin>\n";
         }
     }, 5);
     ```
     (Colócalo a nivel de archivo, junto a los demás hooks; `__FILE__` debe resolver al
     archivo principal del plugin — si lo pones en otro archivo, usa la ruta equivalente.)
4. En `inc/stic-magic-login.php:321-322`: elimina las DOS líneas (`preconnect` + hoja de
   Google Fonts). La pantalla puente pasa a usar el stack de sistema que ya tiene de
   fallback en su propio `<style>` (`font-family:'Inter',system-ui,...` — déjalo tal cual:
   sin la hoja externa, cae a `system-ui`, que es exactamente lo que queremos en una
   pantalla que dura 1-2 segundos).

**Verify**: `ls fonts/` → 5 archivos `.woff2`, cada uno > 10 000 bytes (`wc -c fonts/*`).
**Verify**: `grep -c "@font-face" css/stic-base.css` → 5.
**Verify**: `grep -rn "fonts.googleapis\|fonts.gstatic" --include="*.php" .` → 0 resultados.
**Verify**: `php -l sinergiacrm-private-area.php && php -l inc/stic-magic-login.php` → sin errores.

### Step 3: Cargar el cropper solo en las páginas con subida de archivos

En `sinergiacrm-private-area.php`, `dcms_insertar_js()`: sustituye el enqueue incondicional
(`:146-147`) por el patrón de `$ibanPages` (`:100-111`):

```php
    $cropperPages = array(
        'single_stic_documents',
        'single_stic_comunica_monitor',
        'single_stic_comunica_perfil',
        'single_stic_profile',
    );
    if (in_array($page, $cropperPages, true)) {
        wp_register_script('stic-cropper', plugin_dir_url(__FILE__) . 'js/stic-cropper.js', array(), $jsver('js/stic-cropper.js'), true);
        wp_enqueue_script('stic-cropper');
    }
```

Y AÑADE la página al comentario-mapa de librerías (`:80-93`), que es el contrato documentado
de esa función: `· stic-cropper → páginas con input de imagen (documents, comunica_monitor, comunica_perfil, profile).`

**Verify**: `php -l sinergiacrm-private-area.php` → sin errores.
**Verify**: `grep -n "stic-cropper" sinergiacrm-private-area.php` → dentro del `if` nuevo, no incondicional.

### Step 4 (OPCIONAL — solo si el operador lo pidió explícitamente): retirar DataTables

DataTables (108 KB + rebuild del DOM tras pintar) solo aporta una caja de búsqueda en
cliente (paging/ordering ya están desactivados en los 11 listados). Sustituirlo:

1. En `js/stic-init.js`, sustituye la init de DataTables por un filtro propio: un `input`
   antes de la tabla `#this-list` que haga `toggle` de `hidden` en cada `<tr>` según
   `textContent.toLowerCase().includes(q)` (con `placeholder` sacado del objeto `language`
   de `stic_script_vars`, ver `inc/stic-script-vars.php`). Respeta `data-dt-settings`:
   `searching:false` → no pintar el input.
2. En `sinergiacrm-private-area.php`, elimina el enqueue de `datatables` (`:154-157`) y en
   `sugar_crm_portal_style_and_script` el de `stic-datatables` CSS (`:1198-1202`).
3. `pages/list_stic_contacts.php:26` — quitar la clave `datatables` entera (no usaba nada).

Es un cambio de UX visible (la caja de búsqueda cambia de aspecto): hace falta QA visual en
staging de los 11 listados. Si no puedes verificar visualmente, STOP de este step.

**Verify**: `grep -rn "jquery.dataTables" sinergiacrm-private-area.php` → 0;
`node --check js/stic-init.js` → exit 0; los 11 `pages/list_*.php` pasan `php -l`.

## Test plan

- `composer test` (con vendor/) → verde; ninguno de estos archivos está cubierto por la
  suite, así que el gate real es lint + los greps de cada step.
- Staging (operador):
  1. El área se ve idéntica con la Inter local (comparar una pantalla antes/después).
  2. DevTools → Network: 0 peticiones a `fonts.googleapis.com`/`fonts.gstatic.com`.
  3. Tras un deploy real: `curl -sI <url del css>` y comprobar que el CSS servido pesa
     ~30 % menos que el del repo.
  4. Subir una foto de perfil (cropper funciona en las 4 páginas con archivo).

## Done criteria

- [ ] Workflow YAML válido y con el paso de minificado ANTES del paso FTPS
- [ ] `git diff` no toca el contenido de `css/*.css` salvo los `@font-face` añadidos a `stic-base.css`
- [ ] `grep -rn "fonts.googleapis" --include="*.php" .` → 0
- [ ] 5 woff2 en `fonts/`
- [ ] `stic-cropper` encolado solo en las 4 páginas del censo
- [ ] `php -l` exit 0 en los PHP tocados
- [ ] Fila 031 actualizada en `plans/README.md`

## STOP conditions

- Sin red para descargar los woff2 → STOP del step 2 (los demás siguen).
- El bloque de enqueues no coincide con los extractos (deriva) → STOP del step afectado.
- El minificado de esbuild falla sobre `custom-style.css` (CSS inválido en origen) → STOP
  del step 1 y reporta el error exacto de esbuild.
- Step 4 sin posibilidad de QA visual → STOP de ese step.

## Maintenance notes

- El minificado vive SOLO en el workflow: quien depure en producción verá CSS minificado;
  los fuentes están en el repo. Si esto confunde, la alternativa es committear `.min.css` y
  encolarlos — se descartó para no mantener dos copias.
- Si se añade un JS/CSS propio nuevo, añadirlo a la lista del paso de minificado.
- Al actualizar Inter (nueva versión), regenerar los 5 woff2 y listo — no hay más piezas.
- Google Fonts como origen queda prohibido en el área: si alguien reintroduce un peso nuevo,
  que lo descargue a `fonts/`. El grep del done criteria es el detector.
