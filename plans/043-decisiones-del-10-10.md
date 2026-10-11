# 043 — Lo que decidió el propietario el 10/10/2026, listo para hacer

**Si te dicen «sigue con el área privada», es esto.** Hazlo de arriba abajo,
sin preguntar lo que ya está decidido aquí. Cada fila es autocontenida.

Sale del plan 042 (`plans/042-pulido-integral/`): las respuestas del propietario
en el artefacto de decisiones (https://claude.ai/artifact/9yedWCTkDD2XdxgUTpWfLA,
colección `decisiones` de su base de datos; se pueden releer con la
herramienta de datos del artefacto). Las auditorías con el detalle de cada
hallazgo (problema, evidencia fichero:línea, propuesta) están en
`plans/042-pulido-integral/auditoria-*.md`.

---

## Cómo trabajar (vale para todas las filas)

- Rama nueva desde `main` actualizado. Un commit por fila, en español, al estilo
  del repo. Al acabar: PR a `main` y, si el propietario lo pide, PR de `main` a
  `production` (así se despliega: `.github/workflows/deploy-produccion.yml`).
- `composer lint` y `vendor/bin/phpunit` en verde antes de cada commit. Nunca se
  debilita ni se borra un test para ponerlo verde.
- Lo visual se captura **antes y después** a 375 y 1280 px en claro y oscuro y
  se MIRA (design.md §9). Herramienta: Chromium en
  `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` con Playwright global;
  arneses en `tests/manual/render-*.php`. CLAUDE.md, design.md y
  `docs/comunica/PASAR-LISTA-ESTADO.md` §3 (las trampas) mandan.
- Al terminar una fila, márcala HECHA aquí con la fecha y el commit.

---

## Ya hecho el 11/10 (no repetir)

| Decisión | Qué se hizo |
|---|---|
| VEL-6 · 6 consultas al CRM a la vez | `callMany()` con 6 en vuelo. Vuelta atrás: filtro `sticpa_crm_multi_concurrency` → 4 |
| «Mis inscripciones» (de FAM-a10) | El menú lo dice; viendo a un hijo, «Inscripciones» |
| «Volver» en todos los formularios | Documentos, sesiones, pagos y compromisos |
| Pasar lista en eventos: fuera los de más de 50 días | `sticpa_pl_ev_max_dias()` (10/10) |
| Cola sin cobertura: límite de 72 h | Se queda como está (aprobado) |
| Crear la sesión del día en un evento sin sesiones | Se queda como está (aprobado) |

---

## ⏰ Recordatorio urgente para el propietario: mirar que el CRM aguanta las 6

Pidió que se le recordara **en cuanto esté desplegado**. Dónde mirarlo:

1. **En el navegador** (lo más fácil): abrir el área, herramientas de desarrollo
   (F12) → pestaña *Network* → pulsar la petición de la página → *Timing*. Ahí sale
   «CRM (N llamadas)» con los milisegundos que se ha esperado al CRM
   (cabecera `Server-Timing`, plan 042 VEL-10). Comparar portada y Pasar Lista
   antes y después: debería bajar o quedar igual, nunca subir.
2. **En el hosting**: el `error_log` de PHP. Las líneas `[sticpa] Petición lenta`
   (las escribe `inc/stic-pasar-lista-diag.php`) no deben aumentar la semana
   siguiente.
3. **En el CRM** no hay pantalla para esto; si SinergiaCRM avisa de carga, se
   vuelve a 4 con el filtro `sticpa_crm_multi_concurrency` (una línea, sin tocar código).

---

## Por hacer, en este orden

> **11/10/2026: las filas 1 a 9 están hechas** (PR de la rama
> `claudito/gallant-babbage-n74kag`). Lo que queda es la 10, las pruebas en
> producción, y el lado de la app de «Las flechas de la app» (abajo).

### 1. CAR-1 — Para el equipo, sus herramientas primero (S) — ✅ HECHA 11/10/2026 (`91566b9`)
Detalle: `plans/042-pulido-integral/auditoria-carcasa.md` CAR-1, opción A. Para
quien es del equipo (`sticpa_equipo_es_del_equipo()`), el bloque del equipo va
primero en el menú (`getSticMenuElements()` en `menu.php`), en la barra de
escritorio (`sticpa_nav_layout()`) y en la portada (`pages/single_stic_home.php`,
la sección `stic-home-equipo` antes de `.stic-home-layout`). Las familias y los
miembros sin papel de equipo no cambian. Actualizar el comentario de
`menu.php:10-28` y el test de orden del menú si lo hay.

### 2. CAR-5 punto 2 — «Salir» dentro del menú en el móvil (S) — ✅ HECHA 11/10/2026 (`9240452`)
`auditoria-carcasa.md` CAR-5. En móvil (`max-width: 767px`) quitar «Salir» de la
barra y pintarlo como último elemento del desplegable (`<li class="stic-nav-item
stic-nav-logout-item">`, que ya tiene CSS). Escritorio igual. Quitar de
`menu.php` el comentario «"Salir" SIEMPRE arriba a la derecha».

### 3. FAM-a8 — Cambiar de hijo sin volver a la portada (S) — ✅ HECHA 11/10/2026 (`59ef2e2`)
`auditoria-familias.md` FAM-a8. El selector de la barra manda la página actual
(`internalpage` y, si es `single_stic_events`, su `action=detail&id`); el handler
`prefix_admin_single_stic_profile_selection()` (`inc/stic-action.php`) vuelve a
ella SOLO si está en una lista blanca de páginas no personales: `list_stic_events`,
`single_stic_events` (detalle), `single_stic_activities_calendar`. Validar con
`sticpa_login_destination_args()` (la misma lista blanca del login y de FAM-a1:
mira cómo lo hizo FAM-a1 con el parámetro `dest`). Cualquier otra página → la
portada, como ahora. Tests en `tests/FamilyContextTest.php`.

### 4. PL-10 punto 5 — «chavalines» (S) — ✅ HECHA 11/10/2026 (`72f420f`)
El propietario quiere **«chavalines»** (no «chavales» ni «participantes») en las
pastillas de recuento de Pasar Lista y Mis grupos: «9 chavalines», «1 chavalín»
(con `_n()`). Detalle en `auditoria-pasar-lista.md` PL-10 punto 5. Buscar los
recuentos con `grep -rn "participante" inc/stic-pasar-lista-ui.php
pages/single_stic_pasar_lista*.php pages/single_stic_mis_grupos.php`
(`sticpa_pl_recuento_texto()` es uno). Solo las pastillas y recuentos de gente,
no los nombres de tipos de relación ni textos legales. Ajustar los tests que
fijan el texto.

### 5. A11Y-1 — Anillo de foco visible (S) — ✅ HECHA 11/10/2026 (`741769f`)
`auditoria-a11y.md` A11Y-1: token `--focus-ring` en §1 de `custom-style.css`
(`0 0 0 2px var(--surface), 0 0 0 4px var(--primary-color)`), sustituir
`var(--shadow-glow)` por él en las reglas `:focus-visible` (no en el `:focus` de
los campos), arreglar las dos reglas de «elegido» que se comen el anillo, y
actualizar design.md §4 «Foco». Verificar tabulando el login, un formulario y la
ficha de un evento a 1280 en los dos temas.

### 6. CAR-6 — Cabecera de las fichas sin degradado (S) — ✅ HECHA 11/10/2026 (`03c463b`)
`auditoria-carcasa.md` CAR-6: `.stic-rec-hero` (custom-style.css §49) a superficie
neutra, sin el `::after`; título `--gray-900`, subtítulo y fecha `--gray-600/700`,
icono `--primary-color`, chips con su tono normal (quitar las versiones
translúcidas). Capturar fichas de evento (con y sin cartel), pago devuelto,
pago pagado e inscripción.

### 7. FAM-a13 — Iconos de la portada sin degradado (S) — ✅ HECHA 11/10/2026 (`9f0ae71`)
El propietario: «si queda bien, vale, pero que quede bien». Iconos de acceso de
la portada (`.stic-dash-icon`, custom-style.css) en tinta suave: fondo
`--primary-50`, trazo `--primary-color`, también en oscuro. **Captura antes y
después** a 375 y 1280 en los dos temas y compáralas de verdad. Si no queda
claramente mejor, NO se hace: se apunta aquí por qué y se deja como está.

### 8. FAM-a11 — Selector de hijo a 44 px de verdad (S) — ✅ HECHA 11/10/2026 (`15d6d3b`)
El propietario dijo Sí (aunque se recomendaba dejarlo). `.stic-part-switch-btn`
(custom-style.css, la barra de familia) con `min-height: 44px` visible; la barra
crece ~13 px en todas las pantallas de familia: comprobar que no tapa nada en
375 y dentro de la app.

### 9. F3 — «Lo importante del grupo, de un vistazo» (S) — ✅ HECHA 11/10/2026 (`095a761`)
Ficha completa en `docs/comunica/PROPUESTAS-FUNCIONALIDADES-2026-10.md` F3. En la
lista del grupo (Mis grupos y marcar), una marca discreta junto al nombre, como
mucho tres: «No se va solo/a» (`ajmcm_soloacasa_c` = 0), «Sin fotos»
(`ajmcm_cesionimagenes_interne_c` = 0) y «Alergia o tratamiento» (alguno de
`ajmcm_descripcion_allergies__c` —dos guiones bajos, de verdad—,
`ajmcm_descripcion_intoler_c`, `ajmcm_descripcion_tratam_c` con texto; la marca
NO dice cuál). Y un filtro en Mis grupos: «¿Quién no puede salir en fotos?».
Comprobar ANTES cada nombre en `docs/comunica/CAMPOS.md` (manda) y añadirlos a la
consulta de la lista que ya se hace, sin llamadas nuevas al CRM (mírese el coste
en `tests/CosteLlamadasTest.php`). Si `ajmcm_datossalud_c` es «No», no pintar la
marca de salud. Sobria: nada de filas de emojis (design.md §8).

### 10. Después de desplegar: las pruebas en producción
La lista está en `plans/042-pulido-integral/README.md` «Para comprobar en
producción»: pago con tarjeta, la app en un iPhone (zona segura, barra de
guardar, «volver»), subir un documento, una familia con varios hijos por el
enlace de un evento, pasar lista en un evento (con y sin sesiones, y en modo
avión) y la cabecera `Server-Timing`.

---

## Las flechas de la app y la barra de abajo (11/10/2026)

En Pasar Lista, dentro de MCM App, la cápsula atrás/adelante de la app y la
barra de guardar quedaban una encima de otra. **Lado área: hecho** (`sticpa:barra`,
CONTRATO-APP-WEBVIEW.md §4.b). **Lado app (`mcmespana/mcmapp`): pendiente**:
escuchar `sticpa:barra` en `ComunicaScreen.tsx`, esconder `WebViewNavControls`
con `abajo: true` y poner en `<html>` la clase `sticpa-app-sin-flechas` y
`--stic-app-tabbar`. Mientras no esté, todo sigue como hoy.

## Para más adelante (decidido «sí, pero no ahora»)

- **VEL-8 — Precargar páginas solo con ratón.** En palabras sencillas: cuando
  apoyas el dedo en un enlace, el área empieza a cargar esa página por si acabas
  tocándola. En el móvil casi no adelanta nada y, si el dedo solo iba a hacer
  scroll, esa carga de más retrasa casi un segundo la página que sí tocas. La
  propuesta es hacerlo solo con ratón (en el ordenador). El propietario no lo
  entendió: **explicárselo así y volver a preguntar.**
- **PL-9 punto 3** — enviar la cola sin cobertura desde cualquier pantalla del
  área (hoy, solo desde Pasar Lista).
- **Eventos nacionales en «Pasar lista en un evento».** El propietario: «hay que
  repensarlo; igual hay que hacer un rol de coordinación nacional en relaciones
  con personas que le enseñe los eventos nacionales, da igual de dónde vengan».
  Es decir: un tipo de relación nuevo (coordinación nacional) que, quien lo
  tenga, vea los eventos nacionales con todos sus inscritos. Necesita la clave
  del tipo de relación en `CAMPOS.md` antes de tocar nada.
- **F1 — Añadir al calendario del móvil.** El propietario: «sería algo de MCM App,
  que ya existe; añadirse un evento al calendario no me parece mal». Valorar
  primero qué hace ya la app; si se hace aquí, solo el botón suelto por evento
  (S), no la suscripción.
- **F2 — Inscritos de mi actividad, para quien la organiza.**

## Decidido que NO

- **COO-8** — el aviso de faltas seguidas en la portada de Coordinación.
- **FAM-a10, renombrar «Eventos» a «Apuntarse»** — «Eventos» se queda como está
  (sí se hizo «Mis inscripciones»).
