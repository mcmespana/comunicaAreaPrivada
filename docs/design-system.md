# Sistema de diseño del Área Privada (Comunica / MCM)

> **Para quién es esto:** cualquier persona o agente de IA que tenga que tocar la
> interfaz del área privada. Léelo ANTES de escribir CSS o HTML nuevo. Si sigues
> estas reglas, lo que hagas se verá "del sistema" sin esfuerzo; si no las sigues,
> se notará a la primera.

---

## 1. Principios (en orden de prioridad)

1. **Mobile-first.** La mayoría de familias entra desde el móvil vía enlace de
   email. Todo se diseña primero a 390px y luego se enriquece en escritorio
   (breakpoint principal: `768px`; secundarios: `560/600px` para auth y botones).
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
5. **Tema claro forzado.** El área privada se muestra clara aunque el SO esté en
   oscuro (sección 20.d pisa el dark). No añadas bloques `prefers-color-scheme`
   nuevos sin tener en cuenta ese override final.

## 2. Dónde vive cada cosa (orden de carga = orden de prioridad)

| Archivo | Papel | ¿Se toca? |
|---|---|---|
| `css/stic-style.css` | Base histórica del plugin original | ❌ casi nunca |
| `css/selectize.css` | Librería multiselect | ❌ |
| `css/stic-modern-style.css` | Capa de modernización intermedia | ⚠️ solo arreglos |
| `js/fullcalendar/lib/main.css` | Calendario | ❌ |
| `css/custom-style.css` | **LA capa premium. Carga la última: aquí mandas tú.** | ✅ SIEMPRE aquí |

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

Los grises (`--gray-50`…`--gray-900`) vienen de `stic-modern-style.css` y se
usan por variable. **Regla de oro: para recolorear el área entera solo se
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
| Tooltips de ayuda ⓘ | `.stic-info`, `.stic-info-tip` | §29 CSS · clave `'help'` del motor |
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
5. Pruébalo a 390px ANTES que en escritorio. Botón primario alcanzable, inputs
   de 52px, sin scroll horizontal.
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

- ❌ Colores hex nuevos fuera de los tokens.
- ❌ Selectores globales sin acotar (`button {…}`, `input {…}`).
- ❌ `!important` nuevo salvo para ganar a estilos del tema WP (documenta por qué).
- ❌ Librerías de UI externas (todo es CSS/JS propio y ligero).
- ❌ Texto hardcodeado sin `__()`.
- ❌ Ocultar el botón Guardar bajo el teclado móvil (la botonera es sticky, §28).
- ❌ Duplicar campos entre "Mis datos" y "Monitor/a": lo general vive en Mis datos.
