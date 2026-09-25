# TODO / Roadmap — SinergiaCRM Private Area

Lista viva de tareas del plugin. Pensada para que **agentes de IA o personas**
puedan coger una tarea, entender el porqué y hacerla sin contexto previo.

> Antes de tocar nada, lee [`CLAUDE.md`](CLAUDE.md) y [`README.md`](README.md).
> Los planes con detalle están en [`plans/`](plans/README.md) (los hechos, en
> `plans/archive/`).

---

## 📖 Cómo usar esta lista

**Estado:** `[ ]` pendiente · `[~]` a medias · `[!]` bloqueado / decisión del propietario · `[z]` aparcado a propósito

**Prioridad:** 🔴 **P0** crítico · 🟠 **P1** alto · 🟡 **P2** medio · ⚪ **P3** futuro

**Tamaño:** `S` (< medio día) · `M` (1-3 días) · `L` (semanas)

**Formato:**
```
- [ ] `ID` (Prioridad · Tamaño) Título — qué hay que hacer y cuándo está hecho.
      ↳ pistas: archivos/funciones implicadas.
```

**Reglas:**
1. Prioridad más alta primero, salvo que se pida otra cosa.
2. Al terminar, la tarea sale de aquí y pasa a **Hecho** (al final) en UNA línea.
3. Si una tarea crece, se divide aquí antes de empezar.
4. **No rompas** el acceso ni la conexión al CRM sin avisar.
5. Lo `L` y lo raro **se habla antes** con el propietario.

---

## 🔴🟠 Decisiones del propietario (bloquean trabajo)

- [!] `FAM-02` (P1 · S) **Medio de pago del familiar.** La pantalla usa `ajmcm_pago_*_c`,
      que NO existen: el familiar mete su IBAN y se descarta en silencio. Los campos reales
      (`ajmcm_iban_c`, `ajmcm_iban_titular_c`, `ajmcm_forma_pago_c`, ver CAMPOS.md) viven en
      la ficha del **participante** y esta pantalla edita la del **familiar**. Decidir:
      (a) quitar la sección hasta los compromisos de pago, (b) escribir en cada participante,
      (c) en el familiar. De `ajmcm_forma_pago_c` solo se conoce `cargo_cuenta`.
      ↳ `pages/single_stic_tutor_profile.php` (aviso ⚙️), `plans/015`.
- [!] `DOC-03` (P2 · S) **¿Qué fue de `/aptest/`?** No se quitó desde este repo (es una página
      de WordPress; ningún commit la toca). Da 404 desde el 24/09/2026 y el área de
      pruebas era esa página. Cinco documentos la citan (`design-system.md`,
      `PASAR-LISTA-ESTADO.md`, `PASAR-LISTA-README.md`, `CONTRATO-APP-WEBVIEW.md`,
      `plans/018`). Si se quitó a propósito, cambiarlos a `/ap/`; si no, volver a crearla.

## 🟠 Datos en el CRM (no es código)

- [ ] `CRM-01` (P1 · S) **Entorno personal de Solete** asignado a «Administrador MCM» en vez
      de a MCM Castellón (`00000cd2-159a-eef9-3639-68cd21b90b6a`): la ficha no lo ve.
      Reasignarlo y revisar si hay más así. ↳ `PASAR-LISTA-ESTADO.md` §1.
- [ ] `CRM-02` (P1 · S) **~100 asistencias basura `Unknown - Unknown |`** del 28/08 (sesión
      del 02/05/2026, sin inscripción). Borrado lógico; el propietario dijo que las borra él.
      NO tocar las 24 de Solete ni ninguna con inscripción.
- [ ] `CRM-03` (P2 · S) **Dos `LIS_listas` para la sesión del 02/05/2026** (una de
      monitores, otra de participantes «omitida»): decidir si la omitida es lo que se quiso.
- [ ] `CRM-04` (P2 · M) **Claves de desplegables sin confirmar** en `CAMPOS.md` («Lo que
      queda por revisar»): `ajmcm_dirigido_a_c`, `ajmcm_ambito_c` (falta «nacional»), y si
      `dirigido_a` pasa a múltiple. Mirarlas en Studio y apuntarlas. Una clave mal escrita
      no da error: el filtro deja de acertar en silencio.

## 🟠 Seguridad

- [ ] `SEC-10` (P2 · S) **Pruebas a mano que quedan de la seguridad de los handlers**
      (en producción desde el 24/09/2026; lo demás se comprobó en la web real):
      subir y borrar un documento, inscribirse a un evento, cambiar la contraseña, y con
      una cuenta de FAMILIA cambiar a un hijo y volver. Si algo no guarda, casi seguro es un
      campo `html` sin `'posts'` (ver `inc/stic-security.php`).
- [~] `ADMIN-04` (P1 · M) **«Entrar como»** desde el admin: versión básica hecha. Falta
      registro de quién entró como quién, banner visible y enlace de un solo uso en vez del
      token permanente. ↳ `inc/stic-magic-login.php`.
- [z] `SEC-03` (P3 · M) **Contraseñas sin cifrar** en el CRM. Aparcado por el propietario
      (24/09/2026): se entra sobre todo por enlace, código o DNI. Si se retoma, valorar antes
      retirar el login por contraseña.

## 🟡 Pasar Lista

- [!] `PL-036` (P3 · S) **Lo único que queda del plan 036**, y no es código: el correo
      automático de avisos (se configura en el CRM) y verificar `CAMPOS.md` contra el CRM,
      que necesita el **conector MCP de SinergiaCRM** en la sesión (el 25/09/2026 no estaba).
      El resto del 036 y todo el 037 está hecho (ver `plans/README.md`).

## 🟡 Rendimiento

- [z] `PERF-08` (P3 · M) **Caché de lectura por pantalla**. Aparcado (25/09/2026): con el
      plan 011 y el tope de página aprendido, cada pantalla va en 0,4-1,2 s, y el propietario
      prefiere que un cambio hecho en el CRM se vea al momento. Si se retoma: 1 minuto como
      mucho, que se borre al guardar uno mismo y que el botón de refrescar la salte.
- [z] `PERF-09` (P2 · M) **Techo de filas en los listados** (plan 032): a futuro, hasta que
      alguna lista crezca de verdad. ↳ `plans/032`.

## ⚪ Frontend / diseño

- [~] `UI-18` (P2 · L) **Consolidar CSS** (plan 018): F1 hecha; F2/F3 medidas, sin lote.
- [~] `UI-24` (P2 · M) **Encaje con los grises de WordPress** (plan 024-B): pendiente de
      verlo en el sitio real.

## ⚪ Mantenimiento y calidad

- [ ] `MNT-03` (P3 · M) Healthcheck de la conexión al CRM y de los flujos críticos.
- [ ] `MNT-04` (P3 · S) i18n: revisar que las cadenas nuevas pasen por `__()` y
      actualizar los `.po/.pot`.
- [ ] `MNT-05` (P3 · S) **Funciones que solo usan los tests** (revisado el 25/09/2026):
      `sticpa_pl_titulaciones`, `sticpa_pl_seg_trimestre`, `sticpa_commitment_amount_line`.
      Decidir si se usan en alguna pantalla o se quitan con sus tests. (`mcm_cuerpo_titulo`
      también, pero es del renderizador compartido con los formularios: se queda.)
- [ ] `ADMIN-05` (P2 · S) Campo **URL de portal precalculada** (`ajmcm_pa_portal_url_c`)
      para las plantillas de correo del CRM.
- [ ] `FAM-01` (P1 · M) **Perfiles de familia con Sinergia**: verificar la carga real de
      participantes con relaciones `stic_Personal_Environment` y el rol «familiar».
- [ ] `CI-02` (P3 · S) Entorno de **staging** propio (hoy no hay: `/ap/` es producción).

---

## ✅ Hecho (una línea por tarea; el detalle está en git y en `plans/archive/`)

**Acceso y seguridad:** `AUTH-01..04` token permanente y acceso mágico · `SEC-01` sin
contraseña por correo · `SEC-02` consultas del login escapadas (24/09) · `SEC-04` TLS
verificado (24/09) · `SEC-05` CSRF con la firma atada a la sesión (24/09) · `SEC-06`
cookies seguras + modo estricto (24/09) · `SEC-07` `internalpage` saneado · `SEC-08`
`exit` tras los redirects · `SEC-09` escapado en formularios · planes 001-008: sesión,
propiedad, campos firmados, participante validado, redirecciones seguras, XSS (24/09).

**Admin:** `ADMIN-01..03` buscador, ver/regenerar token, tokens masivos.

**Plataforma:** `PLAT-00` la app es una WebView de esta web (`?app=1`).

**Frontend:** `UI-01..16` estilos, paleta, login, carga, portada, menú, barra, subidas,
modal de borrado, sistema de diseño, formularios Comunica, perfiles de familia, modo app ·
24-25/09: menú del móvil en mosaicos, portada de 2 en 2, pestañas legibles, botones con
texto blanco en claro, sin degradado de fondo, 12 px de margen en toda la web, calendario
con la barra en una fila, buscador sin doble caja, sin cinta de «Pruebas».

**Rendimiento:** `PERF-01` caché de campos · `PERF-02` sin animaciones infinitas ·
`PERF-03` sesión técnica del CRM compartida (25/09) · `PERF-04` foto por endpoint ·
`PERF-05` / plan 011 consultas por fila en tandas + tope de página aprendido (25/09) ·
`PERF-06` keep-alive · `PERF-07` / plan 031 assets condicionales, sin DataTables (25/09).

**Mantenimiento:** `MNT-01` sin funciones de debug · `MNT-02` `getDestinationModule()`
(24/09) · 25/09: 13 funciones muertas y 2 páginas rotas fuera (`single_stic_signup`,
`delete_confirmation`, a `pages/archivo/`).

**Documentación y CI:** `DOC-01..02` · `CI-01` deploy automático a producción.

> Mantén esta lista al día: al terminar, la tarea sale de arriba y entra aquí en una línea.
