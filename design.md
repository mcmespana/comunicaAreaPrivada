# design.md — Comunica MCM

> **Qué es esto.** La ley de diseño de todo lo que Comunica (Movimiento
> Consolación para el Mundo) le enseña a una persona: el área privada, los
> formularios públicos y lo que se vea dentro de la app MCM. Está escrito para
> que un agente que no ha visto nunca este proyecto pueda diseñar una pantalla
> nueva y que salga **de la familia** a la primera.
>
> **Si solo vas a leer una cosa, lee la §2 (orden de prioridad) y la §8
> (reflejos que rechazamos).**

| | |
|---|---|
| **Ámbito** | `comunicaAreaPrivada` (área privada, WordPress plugin) · `comunicaFormularios` (formularios públicos) |
| **Este documento manda sobre** | la *decisión* de diseño: jerarquía, color, tipografía, densidad, movimiento, tono |
| **Este documento NO sustituye** | `docs/design-system.md` (cómo está construido el CSS del área privada: ficheros, secciones, motor de formularios). Ese es el manual de la casa; este es la ley |
| **Fuente de verdad de los campos del CRM** | `docs/comunica/CAMPOS.md`. Nunca inventes un nombre de campo |
| **Verificación** | §9. Un diseño no está hecho hasta que lo has **capturado a 375px y lo has mirado** |

---

## 1. Quién nos lee y desde dónde

No diseñamos para diseñadores. Diseñamos para:

- **Familias y participantes.** Entran desde un enlace de un correo, con el
  móvil, muchas veces con prisa y a veces con poca costumbre digital. No van a
  explorar: van a hacer una cosa concreta y salir.
- **Monitores y monitoras.** Voluntariado. Usan Pasar Lista de pie, en un
  patio, con una mano, con el móvil al 12% de batería y a veces sin cobertura.
- **Delegaciones.** Cada una ve lo suyo y solo lo suyo.

Tres consecuencias que no se negocian:

1. **El móvil es el caso normal, no el reducido.** Se diseña a 375–390px y se
   enriquece hacia arriba. Nunca al revés.
2. **La mayoría del tráfico móvil llega dentro de la WebView de la app MCM**
   (`?app=1`, sin header ni footer de WordPress). Si algo se rompe en móvil, se
   rompe en la app. Contrato: `docs/comunica/CONTRATO-APP-WEBVIEW.md`.
3. **Cada pantalla tiene UNA cosa que la persona vino a hacer.** Esa cosa es el
   único botón de marca de la pantalla. Todo lo demás es secundario o no está.

### Tono

Cercano, claro y breve. Somos una entidad de personas, no un banco ni una
startup. Se puede sonreír; no se puede marear.

- ✅ «Enviarme el enlace» · ❌ «Enviar enlace de acceso»
- ✅ «Sin contraseñas ni líos» · ❌ «Autenticación sin credenciales»
- ✅ «Tu espacio personal. Elige una sección para empezar.»
- Saludo por hora del día antes que un «Bienvenido» plano (`sticpa_greeting()`).
- **Género**: reformula antes de resolver («Te damos la bienvenida» > «Bienvenido/a»).
- **Nunca** texto en pantalla sin `__('…', 'sticpa')` en el área privada.
- Español de España, tuteo, frases cortas. En móvil, más cortas todavía.

---

## 2. Orden de prioridad cuando dos reglas chocan

Sigue este orden. La de arriba gana siempre.

1. **Que se entienda y se pueda tocar.** Contraste AA, objetivo táctil de 44px,
   foco visible, `label[for]`. Un artboard que pide un botón de 40px pierde
   contra el mínimo táctil, y se documenta la divergencia (ya pasó: los botones
   de llamar y WhatsApp de la ficha son de 44, no de 40, *a propósito*).
2. **Que quepa en un móvil de 375px** sin scroll horizontal y con la acción
   principal alcanzable con el pulgar.
3. **Que hable el idioma de la casa**: tokens, componentes que ya existen,
   tarjeta como unidad de registro.
4. **Que sea rápido.** Solo `transform` y `opacity`. Ningún `blur` nuevo.
5. **Que sea bonito.** Es lo último de la lista, y aun así importa: hay
   degradado de marca, cristal y micro-animaciones. Pero al servicio de la
   jerarquía, nunca en su contra.

Si has llegado al 5 saltándote el 1, no has diseñado: has decorado.

---

## 3. La marca

Tres colores y un degradado. No hay más marca que esta.

```
Azul Comunica     #1c6fb3
Violeta puente    #6c4b9e
Magenta Consolación #9d1e74

Degradado de marca: linear-gradient(135deg, #1c6fb3 0%, #6c4b9e 52%, #9d1e74 100%)
```

Reglas del degradado:

- Es la firma. Aparece en la barra de navegación, en el botón primario, en los
  avatares y en la cápsula de fecha. **Uno por pantalla, dos como mucho.**
- **Nunca dos bloques con degradado seguidos** que la persona no pueda cerrar
  (barra de identidad + tarjeta de bienvenida se comían media pantalla). Si
  repites el patrón, dale cierre con memoria y resuélvelo *antes del primer
  pintado*.
- **Los degradados llevan los hex fijos, no los tokens de acento.** En oscuro
  los acentos se aclaran para que el TEXTO se lea; el degradado tiene que
  seguir siendo el de la marca. No mezcles los dos papeles.
- El texto que va **encima** de un relleno de marca es blanco fijo, no
  `--white` (que en oscuro vale `#16171a` y te deja letras negras sobre el
  degradado).

**Verde.** Existe un verde de marca (`#0f8a50`) que identifica los formularios
de participantes, y un verde de estado «éxito» (`#2f9e44`). **No son el mismo
color y no se usan el uno por el otro**: uno es identidad de formulario, el
otro es «esto ha salido bien».

**Recolorear.** Para cambiar el color de todo el producto se editan los tokens
de marca y nada más. Si hay que tocar una regla para recolorear, esa regla
estaba mal escrita.

---

## 4. Tokens: el único sitio donde viven los colores

**Nunca escribas un color en una regla nueva. Nunca escribas un color en un
atributo `style=`** (un inline gana a todo y no hay forma de tematizarlo).

| Superficie | Fichero | Prefijo | Tema |
|---|---|---|---|
| Área privada | `css/custom-style.css` §1 | `--primary-*`, `--secondary-*`, `--surface*`, `--gray-*` | claro **y** oscuro (§44) |
| Pasar Lista | `css/pasar-lista.css` | `--pl-*` (solo lo que no existía) | hereda + oscuro propio |
| Formularios públicos | `crm_comunica_estilos.css` §1 | `--mcm-*`, `--form-*` | **solo claro**, a propósito |

Escala compartida por las dos superficies (mismos valores, verifícalo antes de
inventar uno nuevo):

```
Radios      --radius-sm .55rem · md .85rem · lg 1.1rem · xl 1.4rem · 2xl 1.9rem · full 9999px
Elevación   --shadow-xs/sm/md/lg/xl   (tintadas de azul marino, nunca negro puro)
Foco        --shadow-glow  = anillo de 4px del color de marca al 16%
Curvas      --ease-out    cubic-bezier(0.16, 1, 0.3, 1)
            --ease-spring cubic-bezier(0.34, 1.56, 0.64, 1)
Tipografía  Inter + system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif
```

Si necesitas un color que no existe: **créalo como token** con su valor claro y
—en el área privada— su valor oscuro. No lo escribas en la regla.

> ⚠️ Hoy las dos superficies llaman a los mismos hex con nombres distintos
> (`--primary-color` vs `--mcm-brand-blue`). Está identificado y planificado en
> [`plans/039-unificar-el-lenguaje-visual-mcm.md`](plans/039-unificar-el-lenguaje-visual-mcm.md).
> **Mientras no se ejecute: usa el prefijo del repo en el que estés.** No
> introduzcas un tercer nombre.

---

## 5. Tipografía y escala

Una sola familia: **Inter**, con la pila del sistema detrás. En el área privada
va autoalojada (`fonts/inter-latin-var.woff2`) y siempre se ve Inter.

| Uso | Tamaño / peso |
|---|---|
| Título de página | `clamp(1.4rem, 3vw, 1.85rem)` / 800, con barra de acento a la izquierda |
| Título de tarjeta o sección | `1.05–1.15rem` / 700-800 |
| Cuerpo | `0.9–1rem` / 400-500 |
| Etiqueta de campo | `0.82–0.95rem` / 600 |
| Ayuda, hint, metadatos | `0.78–0.85rem` / 400-500, en `--gray-500`/`--mcm-helper` |
| Chip de estado | `0.72–0.78rem` / 700, mayúscula suave |

Reglas:

- **Nunca una regla universal de tipografía** (`.stic-container *`,
  `.crm-profile-app *`): aplana la jerarquía entera de golpe. Ya pasó.
- El peso hace más jerarquía que el tamaño. Antes de subir 4px, sube de 600 a 700.
- **No repitas en el subtítulo lo que ya dice el título.** Si el subtítulo no
  añade nada, bórralo: es la forma más barata de ganar una pantalla.
- Nada de mayúsculas de caja completa en frases. Solo en chips y etiquetas cortas.

### Espaciado

Los paddings se escriben con `clamp()` (p. ej. `clamp(1.1rem, 2.5vw, 1.6rem)`)
para que el móvil respire sin que el escritorio se infle. Escala de referencia:
`0.25 / 0.5 / 0.75 / 1 / 1.25 / 1.5 / 2 / 2.5 / 3 rem`.

---

## 6. El vocabulario de componentes

**Antes de crear un componente, búscalo.** La tabla completa del área privada
está en `docs/design-system.md` §4, con la clase y la sección de CSS de cada
uno. Lo que hay, resumido: navegación + identidad, selector de participante,
dashboard de tarjetas, formulario por secciones, tooltip ⓘ, hint, nota,
consentimiento con switch, tarjeta de opción (radio), tarjeta de perfil,
listado como tarjetas, dropzone, cropper de fotos, switch Sí/No, modal de
confirmación, estado vacío, botones, overlay de carga, chip, alerta accionable,
campo bloqueado.

### 6.1 La tarjeta ES la unidad de registro

Todo lo que sea *un registro* —un evento, una inscripción, una sesión, un
documento, una persona— se pinta **igual**, lo pinte el renderizador genérico o
uno propio:

```
┌──────────────────────────────────────────┐
│ ┌────┐  Nombre del registro              │  ← cabecera
│ │ 12 │  📅 del 1 al 10 de julio de 2026  │
│ │SEP │  📍 Lugar (si lo hay)             │
│ └────┘  [CHIP DE ESTADO]                 │
│ ─────────────────────────────────────────│
│ [ Secundaria ]      [ ACCIÓN PRINCIPAL ] │  ← barra de acciones al pie
└──────────────────────────────────────────┘
```

- **Cápsula de fecha** a la izquierda (día grande + mes en tres letras).
- **Fechas en lenguaje humano.** «5 de mayo de 2026», «del 1 al 10 de julio de
  2026». **Nunca** `01/07/2026 – 01/07/2026`.
- **El estado es un chip**, no una fila «ESTADO: valor».
- **La barra de acciones va al pie**, separada por una línea, sobre la
  superficie secundaria. La **primera** acción es la principal (degradado de
  marca); las demás, fantasma. **Nunca dos botones de marca en la misma fila.**
- En rejilla de dos columnas, las tarjetas se estiran a la misma altura
  (`flex: 1` en el contenido o `margin-top: auto` en la barra).

**Prohibido presentar un registro como filas «ETIQUETA: valor».** Eso es un
volcado de base de datos, no una pantalla.

### 6.2 Botones

| Papel | Aspecto | Cuántos por pantalla |
|---|---|---|
| Principal | Degradado de marca, blanco, barrido de brillo al pulsar | **Uno** |
| Secundario | Fantasma: borde suave, texto de marca, fondo transparente | Los que hagan falta |
| Suave | Relleno gris muy claro, para acciones de fila | — |
| Peligro | Rojo, y siempre detrás de una confirmación | — |

Altura: 44px+ para la acción principal, 42px mínimo en botones de fila. Inputs
a 52px. El botón de guardar **nunca** queda debajo del teclado móvil: la
botonera es sticky.

### 6.3 Iconos

**SVG en línea, a trazo**: `stroke="currentColor"`, `fill="none"`, 24×24,
`stroke-width: 2`. Nada de fuentes de iconos ni de imágenes.

Los **emoji** tienen exactamente un uso permitido: **el tono de la
conversación** («✨ Ya está», «💡 Un truco»). Un emoji que sea un **marcador de
estado o de categoría** (✅ hecho, ⏳ pendiente, 📙 formación, 👤 avatar) se
sustituye por su SVG. Es la diferencia entre calidez y chapuza.

### 6.4 Formularios

Es el 80% de lo que hacemos. En el área privada **no se maqueta un formulario a
mano**: se declara `$fieldList` y lo pinta el motor (`inc/stic-formController.php`).

- Cada sección es una **tarjeta** con su cabecera (`h5`), colapsable y con
  memoria. Nunca cajas dentro de cajas.
- `help` = tooltip ⓘ, explica **qué** se pide. `hint` = línea gris bajo el
  campo, explica **el formato**. `note` = texto introductorio de sección.
- Móvil: `inputmode` y `autocomplete` **siempre** que existan (email, tel,
  postal-code, bday, name…). Un teclado numérico que sale alfabético es un fallo.
- Campos condicionales con `data-visible-when`, nunca con JS a mano.
- Dos columnas solo a partir de 768px.
- Los campos que vienen del CRM y no se editan se pintan **bloqueados y
  explicados** (`.stic-locked-field`), nunca como un input normal que no guarda.

---

## 7. Modo claro y oscuro

| Superficie | Temas | Por qué |
|---|---|---|
| **Área privada** | claro **y** oscuro, automáticos | Se vive dentro, de noche también. Sigue al dispositivo salvo que la app o la persona digan otra cosa |
| **Formularios públicos** | **solo claro** | Es la hoja de papel donde se escribe. El degradado canta sobre blanco y no compensa mantener dos valores de cada color. **Es una decisión, no un olvido** |

En el área privada:

- El CSS mira **un solo atributo**: `data-stic-scheme` (`light`/`dark`), que es
  el esquema **ya resuelto**. `data-stic-theme` es la *preferencia*
  (`auto`/`light`/`dark`) y el CSS no la mira nunca.
- **Se tematiza redefiniendo TOKENS, jamás reescribiendo reglas.** Si tu regla
  usa `var(--…)`, el oscuro te sale gratis.
- No añadas bloques `prefers-color-scheme` nuevos: el automático ya está resuelto.
- Ojo con los nombres: en oscuro `--success-dark` / `--danger-dark` /
  `--warning-dark` son el **texto claro** que se lee sobre el fondo profundo del
  estado. El sufijo significa «el par de texto de este estado», no «más oscuro».

En los formularios, todo HTML nuevo lleva `<meta name="color-scheme"
content="light">`. Sin eso, el modo oscuro automático de Chrome en Android
invierte la página por su cuenta.

---

## 8. Reflejos que rechazamos

Estos son los patrones que un modelo produce por defecto y que aquí **no
queremos ver**. Si te sale uno, párate.

**De diseño generado**

- ❌ **Todo envuelto en una tarjeta.** Una tarjeta dentro de otra tarjeta dentro
  de un panel. Aplana: una tarjeta por sección, y punto.
- ❌ **Degradado en todas partes.** El degradado es la firma; si está en seis
  sitios deja de firmar nada.
- ❌ **Emoji como sistema de iconos.**
- ❌ **Filas «ETIQUETA: valor»** para presentar un registro.
- ❌ **Rejillas de una columna con tarjetas altísimas.** Lo que decide cuántos
  caben en pantalla manda sobre lo bonito. La home usa 2 columnas tipo «iconos
  de app»: seis accesos sin scroll.
- ❌ **Un subtítulo que repite el título.**
- ❌ **Librerías de UI externas.** Todo es CSS y JS propio y ligero.
- ❌ **Iconos decorativos que no significan nada.**

**Técnicos, y cada uno ha costado tiempo de verdad**

- ❌ **Colores hex fuera de los tokens.** Y **jamás** un color en `style=`.
- ❌ **Selectores globales sin acotar** (`button {…}`, `input {…}`). Esto se
  inyecta dentro de WordPress: un selector global rompe el tema del sitio. Todo
  va colgado de `.stic-container` / `.stic-tab-content` / `.stic-auth-shell` (área
  privada) o `.crm-profile-app` (formularios).
- ❌ **`:hover` sin `@media (hover: hover)`.** En táctil el navegador lo simula
  al tocar y **se queda pegado**. El feedback táctil real es `:active` con
  `scale(0.97)`, y `-webkit-tap-highlight-color: transparent` en todo lo pulsable.
- ❌ **Animar `blur`, `top`, `width` o `background-position`.** Solo `transform`
  y `opacity`. Duración 150–450ms, con `--ease-out` / `--ease-spring`.
- ❌ **Un elemento que nace con `opacity: 0` y se revela con animación sin su
  bloque `prefers-reduced-motion`.** Con movimiento reducido se queda invisible.
- ❌ **`!important` nuevo** salvo para ganar al tema de WordPress, y con un
  comentario que diga por qué.
- ❌ **Meter marcado que no sea un campo dentro de un `.stic-form` sin
  contenedor propio.** Las reglas base alcanzan a cualquier `ul`/`li`/`span` de
  dentro y `.stic-form li span` gana a una clase suelta. Prefija siempre
  (`.stic-auth-aside .stic-auth-perk-ico`, no `.stic-auth-perk-ico`).
- ❌ **Un ancestro con `transform` sobre un tooltip `position: fixed`.** Pasa a
  ser su bloque contenedor y el centrado se rompe.
- ❌ **Adivinar la especificidad.** Mide en el navegador.

---

## 9. Verificación: obligatoria, y así se hace

No hay entorno de pruebas con WordPress. **Un cambio de diseño no está
terminado hasta que lo has capturado y lo has mirado.** Hay Chromium instalado
(`/opt/pw-browsers/chromium-*`).

1. **Render offline.** Monta un HTML que cargue el CSS real y reproduzca el
   marcado real. Si es PHP, se stubean `__()`, `esc_html()`, `get_option()`… y
   se llama a la función que genera el HTML.
2. **Capturas en 375px (el caso duro), 390px y un ancho de escritorio.** En el
   área privada, además, en los **dos temas**. En los formularios, en claro.
3. **Míralas.** De verdad.
4. **Mide, no adivines.** `getBoundingClientRect()` y `getComputedStyle()`
   contestan en un segundo lo que una hora de prueba y error no aclara.
5. **Contra el sitio real** cuando el mock no basta (Astra + Elementor hacen
   cosas que el mock no reproduce). El login es público: descarga la página,
   reescribe los `href` de las hojas a rutas locales y **sírvelo por HTTP**
   (con `file://` el navegador bloquea `document.styleSheets[].cssRules`).
   Entonces puedes recorrer las hojas y preguntar `el.matches(regla.selectorText)`
   para saber exactamente **qué regla y de qué fichero** está ganando.

### Comprobaciones mecánicas antes de dar algo por hecho

```bash
# 1. Ningún color en un atributo style=
grep -rnE 'style="[^"]*(#[0-9a-fA-F]{3,6}|rgb|hsl)' --include=*.php --include=*.html .

# 2. Ningún hex nuevo fuera del bloque de tokens
grep -nE '#[0-9a-fA-F]{6}' css/custom-style.css | awk -F: '$1 > 150'

# 3. Ningún :hover sin su @media (hover: hover) alrededor — revisa a mano los que salgan
grep -n ':hover' css/*.css | wc -l

# 4. Sin scroll horizontal a 375px  (en la consola del render offline)
#    document.documentElement.scrollWidth <= 375

# 5. Objetivos táctiles: todo lo pulsable a 44px
#    [...document.querySelectorAll('a,button,[role=button],input')]
#      .filter(e => e.getBoundingClientRect().height < 44)
```

---

## 10. Antes de dar por buena una pantalla nueva

1. ¿Existe ya el componente? Úsalo tal cual.
2. ¿Es un formulario? Motor de campos, no HTML a mano. Si haces HTML, escapa
   (`esc_html` / `esc_attr` / `esc_url`).
3. ¿Sección nueva? Entrada en el menú + icono y descripción en
   `sticpa_section_meta()`, y el menú y el dashboard la pintan solos.
4. ¿Textos con `__()`, cortos y en el tono de la §1?
5. ¿Capturada a 375px, sin scroll horizontal, con el botón principal alcanzable?
6. ¿CSS nuevo en sección numerada al final del fichero, acotado, y con tokens?
7. ¿Animaciones de 150–450ms y nada imprescindible depende de ellas?
8. ¿`label[for]`, `aria-label` en botones de solo icono, foco visible, tooltips
   con teclado?
9. ¿Un solo botón de marca? ¿Un solo bloque con degradado?
10. ¿Los nombres de campo del CRM salen de `CAMPOS.md`? ¿Y si has tocado
    `CAMPOS.md`, lo has subido **también al otro repo**?

---

## 11. Deuda de diseño conocida

No la repitas y no la des por buena si te la encuentras. Está toda recogida,
con solución propuesta y orden de ejecución, en
[`plans/039-unificar-el-lenguaje-visual-mcm.md`](plans/039-unificar-el-lenguaje-visual-mcm.md):

- Dos vocabularios de tokens (`--primary-*` vs `--mcm-*`) para los mismos hex.
- Breakpoints inventados sobre la marcha en las dos superficies.
- `custom-style.css` con números de sección repetidos (24, 27, 45×3) y una
  §17 «modo oscuro desactivado» que la §44 desmiente.
- Tokens de uso general atrapados en `pasar-lista.css` (`--pl-on-brand`,
  `--pl-brand-fixed`).
- Inter autoalojada en el área privada y solo «si el dispositivo la tiene» en
  los formularios: las dos superficies de la misma marca no comparten letra.
- `docs/design-system.md` §3 cita `stic-modern-style.css`, un fichero que ya no
  existe.

**Si estás tocando una de esas zonas, arréglala de camino y tacha su fila.**
