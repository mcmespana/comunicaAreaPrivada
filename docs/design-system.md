# Sistema de diseño del Área Privada (Comunica / MCM)

> **Relación con [`design.md`](../design.md):** `design.md` es **la ley** —qué se
> decide y por qué, para las dos superficies de Comunica—. Este documento es **el
> manual de la casa**: dónde está cada cosa en ESTE repo. Si los dos dicen algo
> distinto sobre una decisión de diseño, manda `design.md`; sobre dónde vive un
> fichero o una clase, manda este.
>
> **Para quién es esto:** cualquier persona o agente de IA que tenga que tocar la
> interfaz del área privada. Léelo ANTES de escribir CSS o HTML nuevo. Si sigues
> estas reglas, lo que hagas se verá "del sistema" sin esfuerzo; si no las sigues,
> se notará a la primera.

---

## 1. Principios (en orden de prioridad)

1. **Mobile-first.** La mayoría de familias entra desde el móvil vía enlace de
   email. Todo se diseña primero a 375–390px y luego se enriquece en escritorio.
   **Las reglas concretas (breakpoints, tarjetas, densidad, táctil, cómo
   verificar) están en la §11 — léela antes de maquetar nada.**
2. **Un solo lugar para los colores:** los design tokens del bloque `:root` de
   [`css/custom-style.css`](../css/custom-style.css) (sección 1). **Nunca** se
   escriben colores de marca a pelo en reglas nuevas: usa `var(--primary-color)`,
   `var(--grad-brand)`, etc.
3. **Claridad antes que efecto.** Hay glassmorphism y micro-animaciones, pero
   siempre al servicio de la jerarquía. Animaciones de 150–450ms con las curvas
   `var(--ease-out)` / `var(--ease-spring)`; y TODO respeta
   `prefers-reduced-motion` (sección 18 lo anula globalmente).
4. **El CSS del plugin no se escapa.** Toda regla va acotada a
   `.stic-container`, `.stic-tab-content` o `.stic-auth-shell`. Un selector
   global (`button`, `input`, `*`) rompe el tema de WordPress (ya pasó: ver
   PLAN.md "Fuga de estilos al tema").
5. **Tema claro Y oscuro, automáticos.** El área sigue al dispositivo por
   defecto. **Todo se tematiza redefiniendo TOKENS** (sección 44), nunca
   reescribiendo reglas: si una regla nueva usa `var(--…)`, el modo oscuro le
   sale gratis. Nunca un color literal en una regla nueva, y **jamás** un color
   en un atributo `style=` (un inline gana a todo y no hay forma de tematizarlo).
   No añadas bloques `prefers-color-scheme` nuevos: el CSS se engancha a
   `data-stic-scheme`, que ya resuelve el automático. Ver §10.

## 2. Dónde vive cada cosa (orden de carga = orden de prioridad)

| Archivo | Papel | ¿Se toca? |
|---|---|---|
| `css/stic-base.css` | Capa base consolidada (UI-15: ex `stic-style` + `stic-modern-style`, en ese orden) | ⚠️ solo arreglos |
| `css/selectize.css` | Librería multiselect | ❌ |
| `js/fullcalendar/lib/main.css` | Calendario | ❌ |
| `css/custom-style.css` | **LA capa premium. Carga la última: aquí mandas tú.** | ✅ SIEMPRE aquí |

**Modo app (`?app=1`)**: cualquier URL del área con `?app=1` activa una cookie
(30 días) que oculta el header/footer/admin-bar del tema (clase
`body.sticpa-app-mode`, CSS en `sticpa_app_mode_css()`); `?app=0` lo desactiva.
Pensado para la WebView de la app: arranca con `…/?token=XXX&app=1`.
**En móvil este es el caso NORMAL, no la excepción**: el área se ve casi siempre
dentro de la app MCM. Diseña para ahí primero y lee
[`docs/comunica/CONTRATO-APP-WEBVIEW.md`](comunica/CONTRATO-APP-WEBVIEW.md).
El modo app y el tema viven juntos en
[`inc/stic-theme.php`](../inc/stic-theme.php).

El versionado de caché es automático (`filemtime`): al desplegar, el navegador
recarga el CSS/JS solo. No hace falta tocar versiones.

`custom-style.css` está organizado en **secciones numeradas con cabecera en
comentario**. Para añadir un componente nuevo: crea una sección nueva AL FINAL
con su número y nombre, no lo mezcles dentro de otra.

## 3. Design tokens (la fuente de la verdad)

Definidos en `:root` de `custom-style.css` §1. Los importantes:

```css
--primary-color:  #1c6fb3;  /* azul Comunica    */
--secondary-color:#9d1e74;  /* magenta Consolación */
--accent-color:   #6c4b9e;  /* violeta puente   */
--grad-brand      /* degradado azul→violeta→magenta (botones, nav, avatares) */
--grad-brand-rev  /* el inverso (alternancia visual en tarjetas pares) */
--grad-brand-soft /* fondo suave de hover */
--surface / --surface-2      /* blanco / gris azulado muy claro */
--shadow-xs … --shadow-xl    /* elevaciones; --shadow-glow = anillo de foco */
--radius-sm … --radius-2xl, --radius-full
--font-family     /* Inter + system stack */
--ease-out / --ease-spring   /* curvas de animación */
```

Los grises (`--gray-50`…`--gray-900`) viven también en ese `:root` único de
`custom-style.css` §1 (antes estaban en `stic-modern-style.css`, un fichero que
ya no existe: lo consolidó UI-15 y el plan 018 fase 1 movió sus tokens aquí). **Regla de oro: para recolorear el área entera solo se
editan `--primary-*` y `--secondary-*`.**

### Escala visual
- **Texto**: etiquetas de campo `0.82–0.95rem/600`, cuerpo `0.9–1rem`, títulos
  de página `clamp(1.4rem, 3vw, 1.85rem)/800` con barra de acento izquierda
  (automática vía `.stic-tab-content > h3`).
- **Espaciado**: los paddings usan `clamp()` (p. ej. `clamp(1.1rem, 2.5vw, 1.6rem)`
  en tarjetas). Targets táctiles ≥ 44px (inputs 52px de alto mínimo).
- **Elevación**: reposo `--shadow-xs/sm`; hover `--shadow-md/lg` + `translateY(-2px…-6px)`.

## 4. Componentes existentes (REUTILIZA, no reinventes)

| Componente | Clases | Dónde está |
|---|---|---|
| Barra de navegación + identidad | `.stic-nav`, `.stic-nav-bar`, `.stic-account`, `.stic-avatar` | §6-7 CSS · `menu.php` |
| Selector rápido de participante | `.stic-part-switch`, `.stic-part-option` | §33 CSS · `menu.php::sticpa_participant_switcher_html()` |
| Menú overflow "Más" | `.stic-nav-more*` | §6-7 CSS · `js/stic-ui.js::layoutNav` |
| Dashboard de tarjetas | `.stic-dashboard-grid`, `.stic-dash-card` | §8 CSS · `pages/single_stic_home.php` |
| Formularios (tarjetas por sección, 2 col escritorio) | `.stic-form`, headers `h5` | §9, 20, 22 CSS · `inc/stic-formController.php` |
| Tooltips de ayuda ⓘ | `.stic-info`, `.stic-info-tip` (fixed, posicionado por JS con clamping al viewport — nunca se cortan) | §29 CSS · clave `'help'` del motor · `stic-ui.js::positionInfoTip` |
| Consentimiento con switch | `.stic-consent` (frase + checkbox-toggle + enlace "Ver condiciones"; hidden con '0' para guardar No al desmarcar) | §38 CSS · ver sección RGPD de `single_stic_comunica_perfil.php` |
| Cropper de fotos | modal `.stic-crop-card` (arrastrar/pinch/zoom, JPEG 800×800 de vuelta al input) | §39 CSS · `js/stic-cropper.js` (se engancha solo a inputs de imagen) |
| Nota de alerta ámbar | `.stic-form-note.stic-note-warning` | §29 CSS |
| Hint bajo el campo | `.stic-field-hint` | §29 CSS · clave `'hint'` |
| Nota de sección | `.stic-form-note` (+ `.stic-note-soft`) | §29 CSS · tipo `'note'` |
| Consentimiento legal (enlace + Sí/No) | `.stic-legal-row`, `.stic-legal-link` | §30 CSS · ver `single_stic_comunica_perfil.php` |
| Tarjetas de opción (radio) | `.stic-option-grid`, `.stic-option-card` | §31 CSS · ver `single_stic_comunica_monitor.php` |
| Selección de participante | `.stic-profiles-grid`, `.stic-profile-card` | §32 CSS · `pages/single_stic_profile_selection.php` |
| Listados como tarjetas | `.stic-table-responsive`, `.stic-cell-title` | §22 CSS · `inc/stic-listController.php` |
| Dropzone de archivos | `input[type=file]` + badge `.stic-file-uploaded-badge` | §26 CSS |
| Toggle Sí/No (checkbox) | `input[type=checkbox]` estilizado como switch | §25 CSS |
| Modal de confirmación | `.stic-modal-*` | §27 CSS · `js/stic-utils.js::confirmDelete` |
| Estado vacío | `.stic-empty-state` | §28 CSS |
| Botones | `.stic-button` (primario degradado), `.stic-back-button` (secundario), `.stic-danger-button` (peligro), `.stic-soft-btn` (suave), `.stic-legal-link` (píldora outline) | §11, 23 CSS |
| Overlay de carga | `.stic-loading-overlay` (form con clase `stic-loading-form` + `data-loading-text`) | §5 CSS · `js/stic-ui.js` |
| Auth / login | `.stic-auth-shell`, `.stic-auth-tabs`, `.stic-auth-view`, `.stic-field` | §3-4, 24, 34 CSS |
| Control de apariencia (Auto/Claro/Oscuro) | `.stic-appearance*` | §44.j CSS · `sticpa_appearance_switch_html()` |
| Aviso ámbar en línea del motor | `.stic-warning-card` | §47 CSS |
| Campo bloqueado (viene del CRM) | `.stic-locked-field` | §47 CSS |

### Iconos
SVG inline con `stroke='currentColor'`, 24×24, stroke-width 2. Generales en
`sticpa_icon()` y por sección en `sticpa_section_meta()` (ambos en
`sinergiacrm-private-area.php`). **Nunca** fuentes de iconos ni imágenes.
Sección nueva ⇒ añade su icono+descripción a `sticpa_section_meta()` y el menú
y el dashboard la pintan solos.

## 5. El motor de formularios (cómo montar pantallas)

Cada pantalla de `pages/single_*.php` declara `$fieldList` y llama a
`makeForm()`. Claves disponibles por campo (ver cabecera de
[`inc/stic-formController.php`](../inc/stic-formController.php)):

```php
$fieldList[] = array(
    'name' => 'campo_del_crm_c',      // idéntico al nombre en el CRM
    'label' => __('Etiqueta', 'sticpa'),
    'type' => 'text|select|textarea|multienum|date|header|note|html|hidden…',
    'required' => false,               // el flag del CRM no es fiable: sé explícito
    'help' => __('Tooltip ⓘ…', 'sticpa'),      // QUÉ se pide (admite <br>, <strong>)
    'hint' => __('Formato AAAA…', 'sticpa'),   // línea gris bajo el campo
    'placeholder' => 'AAAA',
    'attributes' => array(
        'inputmode' => 'numeric', 'maxlength' => '4', 'autocomplete' => 'bday',
        'data-visible-when' => 'otro_campo:valor1|valor2',  // campo condicional
    ),
);
```

Claves adicionales:
- `'yearOnly' => true` — para campos DATE del CRM que en realidad son "un año":
  el usuario ve/edita solo `AAAA`; al guardar se convierte en `AAAA-01-01`
  (convenio interno, nunca se muestra). Motor emite un hidden
  `stic_year_only_fields[]` y `sticpa_apply_year_only_fields()` (stic-action.php)
  hace la conversión. Usados hoy: `ajmcm_mcm_desde_c`, `ajmcm_monitor_desde_c`.

Comportamientos automáticos del formulario:
- **Secciones colapsables**: cada `h5` pliega su tarjeta y el estado se guarda
  en localStorage por página+sección (`bindCollapsibleSections`, stic-ui.js).
  Los `required` de una sección plegada se desactivan mientras no se ve.
- **Alertas accionables** (`.stic-alert stic-alert--warning`): p. ej. el aviso
  de Certificado de Delitos Sexuales pendiente (modo manual sin archivo), que
  sale en la home y en Monitor/a — `sticpa_monitor_ds_pending()` +
  `sticpa_ds_pending_alert_html()` en inc/stic-comunica-roles.php.

Reglas de UX de formularios:
- **Secciones** con `type => 'header'` (cada una se pinta como tarjeta).
- **Texto introductorio** de sección con `type => 'note'` (variante
  `'classes' => 'stic-note-soft'` para avisos suaves).
- **Tooltips**: replican los `info-icon` de los formularios públicos de
  Comunica. Si el formulario original tenía tooltip, aquí también.
- **Campos año**: `placeholder 'AAAA'` + `inputmode numeric` + `maxlength 4`.
- **Móvil/teclados**: usa `inputmode` y `autocomplete` SIEMPRE que exista
  (email, tel, postal-code, bday, name…).
- **Condicionales**: `data-visible-when` (lo resuelve
  `js/stic-ui.js::bindConditionalFields`; oculta el `<li>` y desactiva su
  `required` mientras no se ve).

## 6. Perfiles de familia (participantes)

Modelo de sesión (todo en `$_SESSION`):

| Clave | Contenido |
|---|---|
| `scp_tutor_user_id` / `scp_tutor_user_contact_name` | El FAMILIAR que inició sesión (fijo) |
| `scp_user_id` / `scp_user_contact_name` | El PARTICIPANTE activo (lo leen TODAS las páginas) |
| `scp_tutor_is_user` | true si el familiar se está viendo a sí mismo |
| `scp_available_profiles` | Participantes disponibles `[{id,name},…]` (caché para el selector) |
| `scp_is_familia` | true si hay participantes a cargo |

Piezas:
- **Pantalla de selección**: `pages/single_stic_profile_selection.php`
  (tarjetas grandes; primera pantalla del familiar tras login).
- **Selector rápido**: `menu.php::sticpa_participant_switcher_html()` — visible
  SIEMPRE en la barra para familias: se sabe en todo momento a quién se ve y se
  cambia en dos toques.
- **Cambio de modo**: handler `prefix_admin_single_stic_profile_selection`
  (inc/stic-action.php) → reescribe `scp_user_*` y redirige.
- **Datos del familiar**: `pages/single_stic_tutor_profile.php` (básicos,
  contacto, dirección y medio de pago).

**Estado de conexión con Sinergia:** las relaciones familiares
(`stic_Personal_Environment`, tipos `RELATIONSHIP_TUTOR_TYPES`) aún no están
montadas en el CRM de Comunica. Mientras tanto:
- `?familia_demo=1` en la pantalla de selección pinta participantes de ejemplo
  (badge "Vista previa") para revisar el diseño;
- el filtro `sticpa_familia_participants` permite inyectarlos desde código;
- el filtro `sticpa_is_familia` fuerza el modo familia.
Cuando el CRM tenga las relaciones, todo funcionará sin tocar código.

**Audiencias de la pantalla de datos** (`sticpa_profile_audience()`):
`single_stic_comunica_perfil.php` sirve a tres audiencias y decide título y
secciones con `$sectionsByAudience` (+ filtro `sticpa_perfil_sections`):
- `miembro` → "Mis datos" (con sección MCM). Incluye al adulto que es familiar
  Y miembro a la vez (si tiene rol, manda el rol).
- `participante` → "Sus datos" (familiar viendo a un menor; el menú también
  cambia a "Sus datos" y se oculta "Monitor/a"). Futuro: añadir aquí las
  autorizaciones de menores (ajmcm_actividadesout_c…, ver CAMPOS.md).
- `familiar` → "Mis datos" del familiar sin rol (sin MCM); su parte
  administrativa (pago) vive en single_stic_tutor_profile.php.
Para divergir contenidos NO se crean páginas nuevas: se ajusta la lista de
secciones y/o se añaden bloques `in_array('xxx', $sections)`.

**Medio de pago (front adelantado):** los campos `ajmcm_pago_metodo_c`,
`ajmcm_pago_iban_c` y `ajmcm_pago_titular_c` de la pantalla del familiar son
**provisionales** (el CRM ignora campos inexistentes, así que guardar es
inocuo). Cuando Sinergia defina dónde viven, renombra los `'name'` en
`single_stic_tutor_profile.php` y borra el aviso ⚙️ de la nota.

## 7. Formularios Comunica (monitores / laicos)

Los formularios públicos de referencia viven en el repo `comunicaFormularios`
(`monitores/monitores.html`, `com-lc/laicos.html`). El área privada los replica
FUNCIONALMENTE (mismos campos, orden, tooltips y textos; la estética es la del
área privada):

- **"Mis datos"** (`single_stic_comunica_perfil.php`) = TODOS los datos
  generales: identidad (solo lectura + aviso ✱), contacto (con tooltip del
  contacto de emergencia), dirección, foto, MCM (etapa/pañuelo/nivel COM/año
  LC/talla/grupo/MCM local/"pertenezco desde"), información sanitaria (5
  campos con tooltip) y autorizaciones RGPD (con enlaces a los textos legales).
  El formulario de laicos NO pide nada más → no existe sección "Laico/a".
- **"Monitor/a"** (`single_stic_comunica_monitor.php`) = lo específico:
  trayectoria, formación (premonitores/MAT/DAT/FA con sus tooltips, congresos,
  formación académica), voluntariado, certificado de delitos sexuales (tarjetas
  Automático/Manual → campo `ajmcm_aut_del_sex_c` + subida manual) y archivos
  (MAT/DAT/otros con badge "Ya subido").
- **Exclusión deliberada**: las preguntas/foto de la Asamblea de mayo de 2026
  no se replican (el evento ya pasó).
- Catálogo completo de campos del CRM: [`docs/comunica/CAMPOS.md`](comunica/CAMPOS.md).

## 8. Checklist para pantallas nuevas

1. ¿Existe un componente en §4? Úsalo tal cual.
2. Campos → motor de formularios con `help`/`hint`/`note` (no HTML a mano salvo
   necesidad real; si haces HTML, escapa con `esc_html`/`esc_attr`/`esc_url`).
3. Sección nueva → entrada en `getSticMenuElements()` (menu.php) + icono/desc
   en `sticpa_section_meta()`.
4. Textos SIEMPRE con `__('…', 'sticpa')`, en español neutro y cercano.
5. Pruébalo a 375/390px ANTES que en escritorio. Botón primario alcanzable,
   inputs de 52px, sin scroll horizontal. **Lee la §11 (Móvil) entera**: ahí
   están el lenguaje de tarjetas, la densidad, lo táctil y cómo verificar.
6. CSS nuevo → sección numerada al final de `custom-style.css`, acotado a
   `.stic-container`/`.stic-auth-shell`, tokens en vez de colores.
7. Animaciones: 150–450ms, `--ease-out`/`--ease-spring`, y nada imprescindible
   debe depender de ellas (reduced-motion las apaga).
8. Accesibilidad mínima: `label[for]`, `aria-label` en botones de solo icono,
   foco visible (`--shadow-glow`), tooltips usables con teclado.
9. Redirecciones en handlers: `wp_redirect(...); exit;` SIEMPRE. El `id` sobre
   el que se escribe sale de `$_SESSION`, nunca del request.
10. ¿Página nueva en `pages/`? El nombre debe cumplir `[a-z0-9_]+`
    (`sticpa_resolve_page_file` rechaza cualquier otra cosa).

## 9. Anti-patrones (cosas que NO se hacen)

- ❌ Colores hex nuevos fuera de los tokens (§1 para el claro, §44 para el oscuro).
- ❌ Colores dentro de un atributo `style=` (imposible de tematizar; usa una clase).
- ❌ Selectores globales sin acotar (`button {…}`, `input {…}`).
- ❌ `!important` nuevo salvo para ganar a estilos del tema WP (documenta por qué).
- ❌ Librerías de UI externas (todo es CSS/JS propio y ligero).
- ❌ Texto hardcodeado sin `__()`.
- ❌ Ocultar el botón Guardar bajo el teclado móvil (la botonera es sticky, §28).
- ❌ Duplicar campos entre "Mis datos" y "Monitor/a": lo general vive en Mis datos.
- ❌ Filas "ETIQUETA: valor" para presentar un registro: usa la tarjeta de §11.2.
- ❌ Efectos de `:hover` sin `@media (hover: …)`: en móvil se quedan pegados.
- ❌ Dos bloques con degradado de marca seguidos que no se puedan cerrar (§11.4).
- ❌ Dar por buena una pantalla sin haberla capturado a 375px (§11.8).

## 10. Tema claro / oscuro

**Automático por defecto**: el área sigue la apariencia del dispositivo. Toda la
lógica de servidor vive en [`inc/stic-theme.php`](../inc/stic-theme.php) y todo
el CSS en `custom-style.css` **§44**.

### Los dos atributos

| Atributo | Valores | Qué es | Quién lo pone |
|---|---|---|---|
| `data-stic-theme` | `auto` \| `light` \| `dark` | la **preferencia** | `sticpa_theme_attr()` (contenedores) y el filtro `language_attributes` (`<html>`) |
| `data-stic-scheme` | `light` \| `dark` | el **esquema resuelto**; **es el único que mira el CSS** | PHP si la preferencia es explícita; el script inline de `<head>` (`sticpa_theme_boot_js`) si es `auto` |

### Quién decide (orden)

1. **La app MCM** si estamos en su WebView (`?app=1`): `?theme=` y luego la cookie
   `mcm_theme`. Contrato: [`comunica/CONTRATO-APP-WEBVIEW.md`](comunica/CONTRATO-APP-WEBVIEW.md).
2. **La cookie propia `sticpa_theme`**, que escribe el control *Apariencia* del
   pie del área (`.stic-appearance`, §44.j). **No** se pinta en modo app: la app
   ya tiene su selector y dos interruptores es peor que uno.
3. **El dispositivo** (`prefers-color-scheme`), resuelto en el cliente antes del
   primer pintado (sin flash) y re-resuelto si el dispositivo cambia en caliente.

Sin JavaScript, `auto` se queda en claro (el comportamiento de siempre).

### Cómo se tematiza algo nuevo

1. Usa `var(--…)` para **todos** los colores → ya funciona en oscuro.
2. Si necesitas un color que no existe como token: créalo en **§1** con su valor
   claro y dale su valor oscuro en **§44.a**.
3. Si el elemento se ancla a `<body>` (overlay, tooltip, modal, desplegable de
   Selectize), añade su clase a la lista de anfitriones de tokens de §44.a: si no,
   hereda los tokens claros del `:root`.
4. Ojo con los nombres: en oscuro `--success-dark` / `--danger-dark` /
   `--warning-dark` son el **texto CLARO** que se lee sobre el fondo profundo del
   estado. El sufijo significa "el par de texto de este estado", no "más oscuro".
5. La marca tiene dos papeles en oscuro: los **tokens** (`--primary-color`…) se
   aclaran para que el TEXTO sea legible, y los **degradados** (`--grad-brand`)
   se fijan con los hex de marca originales para que la barra de navegación y los
   botones sigan siendo los de la marca. No lo mezcles.

### Verificación

- `tests/ThemeTest.php` fija el contrato de prioridades y el saneado (el `?theme=`
  y las cookies son entrada de usuario: solo se aceptan `'light'` y `'dark'`).
- A mano, las **cuatro** combinaciones: {SO claro, SO oscuro} × {apariencia auto,
  apariencia forzada}, sobre login, home, "Mis datos", un listado, el calendario,
  el modal de borrado y el cropper. Y con `?app=1`.

## 11. Móvil: cómo se diseña aquí

> Esta es la sección que hay que leer antes de maquetar CUALQUIER pantalla nueva.
> Recoge las decisiones que ya están tomadas y en producción (planes 022, 023,
> 025 y 026) para que lo siguiente que se haga se vea de la misma familia.
>
> La regla madre: **el móvil no es una versión reducida del escritorio, es el
> caso normal.** La mayoría entra desde el móvil, desde un enlace de correo.

### 11.1 Breakpoints (usa estos, no inventes)

| Ancho | Para qué |
|-------|----------|
| `≤ 340px` | Rescate de móviles muy estrechos: lo que iba en 2 columnas pasa a 1, en horizontal |
| `≤ 640px` | **El breakpoint móvil de referencia.** Densidad, tipografías y "qué se oculta" |
| `≤ 767px` | Navegación colapsada (hamburguesa) y calendario |
| `≥ 768px` | Dos columnas en formularios y listados |
| `≥ 860px` | Login partido en dos (marca + formulario) |
| `≥ 1024px` | Home con la agenda en columna lateral |

Los `560/561px` y `600px` que verás en el CSS son históricos (auth y botones).
No añadas breakpoints nuevos si uno de estos sirve.

### 11.2 UN solo lenguaje para "un registro" (la tarjeta)

Todo lo que sea un registro — un evento, una inscripción, una sesión, un
documento — se pinta **igual**, venga del renderizador genérico
(`makeList`, `inc/stic-listController.php`) o de uno propio
(`sticpa_events_list_html`, `inc/stic-events.php`):

```
┌──────────────────────────────────────────┐
│ ┌────┐  Nombre del registro              │  ← cabecera
│ │ 12 │  📅 del 1 al 10 de julio de 2026  │
│ │SEP │  📍 Lugar (si lo hay)             │
│ └────┘  [CHIP DE ESTADO]                 │
│ ─────────────────────────────────────────│
│ [ Secundaria ]      [ ACCIÓN PRINCIPAL ] │  ← barra de acciones
└──────────────────────────────────────────┘
```

Reglas:
- **Cápsula de fecha** (día grande + mes) a la izquierda. En listados genéricos
  se activa declarando `$listSettings['cardDate'] = '<columna>'`; si no se
  declara, la tarjeta se pinta sin ella y no se rompe nada.
- **Fechas en lenguaje humano**, nunca `01/07/2026 – 01/07/2026`. Un evento de
  un día dice "5 de mayo de 2026"; uno del mismo mes, "del 1 al 10 de julio de
  2026" (ver `sticpa_event_date_line()`).
- **El estado es un chip** (`.stic-chip`), no una fila "ESTADO: valor".
- **La barra de acciones va al pie**, separada por una línea y sobre
  `--surface-2`. La **primera** acción es la principal (`--primary`, degradado
  de marca); las demás, `--ghost`. Nunca dos botones de marca en la misma fila.
- En la rejilla de 2 columnas de escritorio las tarjetas se estiran a la misma
  altura: la zona de contenido lleva `flex: 1` (o la barra `margin-top: auto`)
  para que los botones queden abajo y no a media altura.

Clases: `.stic-ev-*` (renderizador de eventos) y `.stic-cell-*` / `.stic-rowbtn`
(genérico). Comparten aspecto a propósito; si tocas uno, mira el otro.

### 11.3 Densidad: qué se cae en móvil

- **Descripciones y textos de CTA se ocultan** cuando la tarjeta entera ya es el
  enlace (las tarjetas de la home muestran solo icono + nombre; en la selección
  de participante el CTA se queda en la flecha). No es "esconder", es que en 2
  columnas de 180px la descripción no cabe y el destino ya lo dice el nombre.
- **Nada de rejillas de una sola columna con tarjetas altas.** La home usa
  2 columnas tipo "iconos de app": 6 accesos a la vista sin scroll.
- Lo que decida "cuántos caben en pantalla" manda sobre lo bonito.

### 11.4 Bloques de marca repetidos: que se puedan cerrar

Dos degradados de marca seguidos (barra de identidad + tarjeta de bienvenida)
se comen media pantalla de móvil y repiten el nombre dos veces. Pero **la
bienvenida con tu nombre gusta y da carácter**: el problema no es la tarjeta,
es verla SIEMPRE.

La solución que usa la home (§51 del CSS): la tarjeta se queda como está y se
le añade un **cierre con memoria**. Al cerrarla se guarda la fecha en
`localStorage` y no vuelve hasta pasado un mes.

Si repites este patrón, tres reglas:
1. **Resuélvelo antes del primer pintado.** Un mini script inline en `<head>`
   (ver `sticpa_welcome_boot_js` en `inc/stic-theme.php`) marca `<html>` con una
   clase y el CSS esconde el bloque. Si lo hace el JS del final, el usuario ve
   la tarjeta aparecer y desaparecer, que es peor que dejarla siempre.
2. **Sin JavaScript se ve.** El servidor siempre pinta el bloque; esconderlo es
   una mejora progresiva, nunca un requisito.
3. **Quítalo del DOM al cerrar** (tras la animación) para que no deje hueco.

Primero pregúntate si el bloque aporta algo: si no, no lo pintes. Esto es para
lo que **sí** aporta pero cansa a diario.

### 11.5 Táctil, no hover

- Los efectos de `:hover` (elevación, giro de iconos) van dentro de
  `@media (hover: hover)`, o se anulan en `@media (hover: none)`. Sin esto el
  navegador los simula al tocar y **se quedan pegados** hasta que tocas en otro
  sitio.
- El feedback real en táctil es `:active` (`transform: scale(0.97-0.98)`).
- `-webkit-tap-highlight-color: transparent` en todo lo pulsable (el recuadro
  gris del navegador se come el diseño).
- Objetivo táctil: **42px mínimo** en botones de fila, **44px+** en acciones
  principales, inputs a 52px.

### 11.6 Textos

Cortos, claros y cercanos — y en móvil, más cortos todavía:
- ✅ "Enviarme el enlace" · ❌ "Enviar enlace de acceso"
- ✅ "Tu espacio personal. Elige una sección para empezar."
- ✅ "Sin contraseñas ni líos"
- Saludo por hora del día (`sticpa_greeting()`) antes que un "Bienvenido" plano.
- No repitas en el subtítulo lo que ya dice el título.
- Género: evita "Bienvenido/a" cuando puedas reformular; si hay que resolverlo,
  se infiere (ver `sticpa_guess_self_label()`), con "mismo" como recurso neutro
  ante la duda.

### 11.7 Movimiento (sin pagarlo caro)

- Solo `transform` y `opacity`. **Nada de `blur` nuevo** ni de animar `top`,
  `width` o `background-position`: son las operaciones caras en GPU de móvil
  (el plan 020 lo midió sobre el login).
- Entradas escalonadas con `animation-delay` (40–60ms entre elementos), 150–450ms.
- Si un elemento nace con `opacity: 0` y se revela con una animación, **acuérdate
  del bloque `prefers-reduced-motion`**: sin animación se queda invisible.

### 11.8 Cómo verificar (obligatorio antes de dar algo por hecho)

No hay entorno de pruebas con WordPress, así que la verificación es **render
offline con Chromium** (ya instalado, `/opt/pw-browsers/chromium-*`):

1. Monta un HTML que cargue `css/custom-style.css` y reproduzca el marcado real
   (si es PHP, se puede stubear `__()`, `esc_html()`, `get_option()`… y llamar a
   la función que genera el HTML).
2. Captura al menos **375px** (iPhone SE, el caso duro), **390px** y un ancho de
   escritorio.
3. Míralas. Un cambio de CSS no está terminado hasta que lo has visto.
4. Si algo no cuadra, **mide en el navegador** (`getBoundingClientRect`,
   `getComputedStyle`) en vez de adivinar la especificidad: en este CSS hay
   `!important` portantes y reglas antiguas que ganan por número de elementos
   (ver §24 vs §50 en el login).

**Contra el SITIO REAL (cuando el render offline no basta).** El área privada
vive dentro de un WordPress con Astra + Elementor, y ahí pasan cosas que el
mock no reproduce. La página de login es pública, así que se puede auditar sin
credenciales:

```bash
curl -sSL https://comunica.movimientoconsolacion.com/aptest -o page.html
# descargar cada <link rel=stylesheet>, reescribir los href a rutas locales
python3 -m http.server 8899      # ¡por HTTP! con file:// el navegador
                                 # bloquea document.styleSheets[].cssRules
```
Sirviéndolo por HTTP puedes recorrer `document.styleSheets` y preguntar
`el.matches(regla.selectorText)` para saber **exactamente qué regla y de qué
archivo** está ganando. Es la diferencia entre arreglarlo y probar cosas.

### 11.9 La trampa de `.stic-form`

La tarjeta de login lleva las clases `stic-login-form` **y `stic-form`**. Y
`stic-base.css` estila los campos así:

```css
.stic-form ul      { display: block; width: 100%; }
.stic-form li      { display: block; width: 100%; padding-bottom: 1rem; }
.stic-form li span { display: block; width: 100%; }
```

Esas reglas **alcanzan a cualquier `ul`/`li`/`span` que metas dentro de la
tarjeta**, aunque no sea un campo. Y `.stic-form li span` (1 clase + 2
elementos) le gana a una clase suelta como `.stic-auth-perk-ico`: el icono se
estiraba a todo el ancho y el texto se salía del panel por debajo del
formulario (pasó de verdad, en producción).

**Regla:** si metes marcado que no sea un campo dentro de un `.stic-form`,
cuélgalo de un contenedor propio y **prefija todos los selectores con él**
(`.stic-auth-aside .stic-auth-perk-ico`, no `.stic-auth-perk-ico`). Dos clases
ganan a una clase + elementos, y te ahorras el `!important`.
