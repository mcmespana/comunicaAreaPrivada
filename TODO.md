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

## 🙋 Te toca a ti (decisiones, Studio y datos del CRM)

Nada de esto es código; sin ello, el código que lo espera no avanza.

**Decidir**
- [ ] `PAG-01` (P1 · M) **Pagos: un solo flujo** — [`plans/041`](plans/041-pagos-un-solo-flujo.md).
      Hecho en el área el 01/10 (pagar con tarjeta sustituye el pendiente, Pagos en tres
      bloques, el organizador es el dueño, fuera «aportación» y «compromiso»). Te queda el
      **formulario avanzado del Foro**: que pregunte cómo se paga (transferencia o tarjeta) y
      cree el compromiso con ESE medio (no «Especie»), de ECE. Y en el correo, las dos opciones.
- [!] `FAM-02` (P1 · S) **Medio de pago del familiar**: la pantalla usa `ajmcm_pago_*_c`, que
      NO existen, y el IBAN que se mete se pierde. Los campos buenos (`ajmcm_iban_c`…) están
      en el participante. Decidir: quitar la sección, escribir en cada participante o en el
      familiar. ↳ `pages/single_stic_tutor_profile.php`, `plans/015`.
- [!] `PL-MON-2` (P1 · S) **Los eventos de prueba 2025-2026 ya no salen** en Pasar Lista (coge
      los del curso del nombre, «2026-2027»). Decidir si se prueba sobre los del curso nuevo o
      hace falta fijar el curso.
- [!] `PL-MON-3` (P1 · M) **Coordinación de toda la delegación con MIC y COM en eventos
      separados**: la lista de monitores usa el de COM para todos. Propuesta: selector de
      etapa cuando el alcance es la delegación. ↳ `pages/single_stic_pasar_lista_monitores.php`.
- [!] `PL-MON-6` (P2 · S) **Las inscripciones que crea Pasar Lista van sin `status`**
      (obligatorio en el CRM). Decidir cuál (¿`confirmed`?).
- [!] `DOC-03` (P2 · S) **`/aptest/` da 404** desde el 24/09/2026 (es una página de
      WordPress). Si se quitó a propósito, cambiar a `/ap/` los cinco documentos que la
      citan; si no, volver a crearla.

- [ ] `PAG-02` (P3 · M) **Pagar por transferencia desde un pago pendiente**: que, junto a «Pagar con
      tarjeta», salgan las instrucciones de transferencia de ESE evento o de su delegación
      (cuenta, concepto, a quién mandar el justificante). Hoy van solo en el correo. Idea del
      propietario (02/10): sacarlas de la delegación (organización) en MCM Bank. Para el futuro.

**Crear en Studio** (el código está hecho y se activa solo)
- [x] `EV-6` (P2 · S) **Preguntas simples — campos creados el 30/09**: `ajmcm_pregunta_1_c` y `ajmcm_pregunta_2_c` en
      Eventos, `ajmcm_respuesta_1_c` y `ajmcm_respuesta_2_c` en Inscripciones (texto 255).
      Otros nombres → cambiarlos en `sticpa_event_question_fields()`.
- [x] `EV-3` (P1 · M) **Enlace del formulario web avanzado — en el área, hecho el 02/10**: con `ajmcm_fwa_url_c`
      relleno, «Inscribirme» lleva al FWA ya relleno (nombre, apellidos, correo, móvil, DNI y soporte) y el
      alta corta no se ofrece ni se guarda. EVENTOS.md §10.2.2.
      - [ ] Las dos puertas en la **página pública** del evento (`comunicaFormularios`).
      - [ ] **Configurar en cada FWA** a dónde vuelve al terminar: el área, `…/ap/?internalpage=list_stic_registrations`.
- [!] `EV-12` (P2 · S) **Ocultar en el área privada**: crear `ajmcm_ocultar_area_c` (casilla, sin marcar) en Eventos. El código ya está; ver `CAMPOS.md`.
- [ ] `EV-4b` (P3 · S) **Pegar la ayuda en la ficha de Eventos**: campo de tipo HTML con
      `docs/comunica/AYUDA-FICHA-EVENTO.html`, arriba en la vista de edición. Y pegar la guía
      nueva en su página de WordPress.

**Datos del CRM**
- [ ] `CRM-05` (P1 · S) **Cuota de Solete sin compromiso**: la inscripción «Solete Villarroya
      Meseguer -» (`00000900-db01-acfe-2649-6ab30955f412`, COM Curso 2026-2027) no tiene
      compromiso de pago. Crearlo a mano (20 €, Domiciliación, Cuota, Pago único, pagadora la
      madre, MCM Castellón) y arreglarle el nombre. Las otras ~150 con IBAN, sin revisar.
- [ ] `CRM-03` (P2 · S) **Dos `LIS_listas` de pruebas** de la sesión del 02/05/2026 del evento
      de prueba 2025-2026 (`00000408…` monitores/pasada y `000007da…` participantes/omitida).
      Sus asistencias ya no existen: borrarlas.
- [ ] `CRM-04` (P2 · S) **Claves de dos desplegables sin confirmar**: `ajmcm_dirigido_a_c`
      (se esperan `grupo`, `monitor`, `participante_mic_com`, `coordinacion`,
      `familiar_menor`) y `ajmcm_ambito_c` (`local`, falta confirmar `nacional`). Mirarlas en
      Studio y apuntarlas en `CAMPOS.md`: una clave mal escrita no da error, deja de filtrar.
- [!] `PL-MON-11` (P2 · S) **Relaciones de monitor sin `end_date`** de gente que ya no es
      monitor: salen en la lista. Cerrarlas en el CRM (las abiertas desde antes del 1/09).
- [!] `PL-036` (P3 · S) **El correo automático de avisos** de Pasar Lista: se configura en el
      CRM. Es lo único que queda del plan 036.

**Probar en producción**
- [ ] `SEC-10` (P2 · S) **Pruebas a mano**: subir y borrar un documento; cambiar la
      contraseña; con una cuenta de familia, cambiar a un hijo y volver; inscribirse a algo con
      precio por Bizum, por domiciliación y con tarjeta (un compromiso, atado y de la
      delegación); modificar y cancelar una inscripción; entrar por el enlace del correo desde
      la ficha de un evento. Si algo no guarda, casi seguro es un campo `html` sin `'posts'`.
- [ ] `PL-MON-1` (P1 · S) **Guardar la lista de monitores en el CRM real**: «Guardado» a la
      primera, sin duplicados al repetir, inscripciones con el nombre del evento y el motivo en
      `description` (`&pl_diag=1` enseña llamadas y tiempos).
- [ ] `PL-MON-10` (P1 · S) **Las altas en lotes** (`set_entries`, 27/09): asistencias atadas,
      sin duplicados, y el tiempo en el diario de guardados. ↳ `sticpa_pl_crear_en_lotes()`.

---

## 🛠 Código

**Pasar Lista** (por orden de lo que más se va a notar el 24/10)
- [ ] `PL-MON-4` (P1 · M) **Asistencias de una sesión sin páginas de 20 en fila**
      (`get_relationships` corta en 20: 4-5 viajes seguidos un sábado lleno). Medir con
      `pl_diag`; si se confirma, `get_entry_list` con el filtro de un día.
      ↳ `sticpa_pl_session_attendances()`, `sticpa_pl_attendance_days_sql()`.
- [ ] `PL-MON-5` (P2 · M) **Los avisos de la lista de monitores leen el curso entero** de la
      delegación. Leerlos por las inscripciones de los monitores, o que el Guardián deje el
      porcentaje calculado de noche.
- [ ] `PL-MON-7` (P2 · S) **Los números de la lista de monitores cuando dos etapas comparten
      reunión**: contar a todos los de la sesión, sin llamadas extra. ↳ `sticpa_pl_save_monitors()`.
- [ ] `PL-MON-9` (P3 · S) **Una reunión suspendida no se puede quitar** (no hay borrar ni «Sin
      registro»). ↳ `pages/single_stic_pasar_lista_reuniones.php`.
- [ ] `PL-MON-8` (P3 · S) Apagar el refuerzo de enlaces (`sticpa_pl_refuerzo_enlaces`) si se
      comprueba que los campos planos atan solos; y que crear una reunión tire solo las
      sesiones de su evento, no toda la caché.

**App MCM** (otro repo)
- [ ] `EV-10` (P2 · S) **Pasar el destino al abrir el enlace del correo**: el puente
      `/app/acceso` ya reenvía `internalpage`, `action`, `id` y `from`; la app tiene que
      pasarlos a la WebView. ↳ `mcm-app/app/+native-intent.ts`, `CONTRATO-APP-WEBVIEW.md` §5.

**Área privada**
- [ ] `FAM-01` (P1 · M) **Perfiles de familia**: verificar la carga real de participantes con
      `stic_Personal_Environment` y el rol «familiar».
- [~] `ADMIN-04` (P1 · M) **«Entrar como»**: falta el registro de quién entró como quién, un
      banner visible y un enlace de un solo uso. ↳ `inc/stic-magic-login.php`.
- [ ] `ADMIN-05` (P2 · S) Campo **URL de portal precalculada** (`ajmcm_pa_portal_url_c`) para
      las plantillas de correo del CRM.
- [~] `UI-18` (P2 · L) **Consolidar CSS** (plan 018): F1 hecha; F2/F3 medidas, sin lote.
- [~] `UI-24` (P2 · M) **Encaje con los grises de WordPress** (plan 024-B): verlo en el sitio.
- [ ] `MNT-05` (P3 · S) **Funciones que solo usan los tests**: `sticpa_pl_titulaciones`,
      `sticpa_pl_seg_trimestre`, `sticpa_commitment_amount_line`. Usarlas o quitarlas.
- [ ] `MNT-04` (P3 · S) i18n: cadenas nuevas por `__()` y `.po/.pot` al día.
- [ ] `MNT-03` (P3 · M) Healthcheck de la conexión al CRM y de los flujos críticos.
- [ ] `CI-02` (P3 · S) Entorno de **staging** propio (hoy `/ap/` es producción).

---

## 💤 Aparcado a propósito

- [z] `EV-5` Campos del DNI en Personas (número de soporte, expedición o caducidad): cuando se
      creen en Studio, a `CAMPOS.md`.
- [z] `SEC-03` Contraseñas sin cifrar en el CRM (24/09): se entra por enlace, código o DNI; si
      se retoma, valorar retirar el login por contraseña.
- [z] `PERF-08` Caché de lectura por pantalla (25/09): las pantallas van en 0,4-1,2 s y se
      prefiere ver al momento los cambios del CRM.
- [z] `PERF-09` Techo de filas en los listados (plan 032), hasta que alguna lista crezca.

---

## ✅ Hecho (una línea por área; el detalle está en git y en `plans/archive/`)

- **Acceso y seguridad:** `AUTH-01..04` token y acceso mágico · `SEC-01..09` y planes
  001-008 (sesión, propiedad, campos firmados, CSRF, cookies, TLS, XSS, redirecciones).
- **Admin:** `ADMIN-01..03` buscador, ver/regenerar token, tokens masivos.
- **Plataforma:** `PLAT-00` la app es una WebView de esta web (`?app=1`).
- **Eventos e inscripciones (25-27/09):** `EV-1` ficha con cartel a la izquierda y agenda a
  la ficha · `EV-2` cancelar y modificar dentro de plazo · `EV-7` compromiso de pago al
  inscribirse (uno solo, de la delegación) · `EV-8` el destino sobrevive al login · `EV-4`
  guía en tres puertas y ayuda para la ficha del CRM · `EV-11` curso y clase con su etiqueta ·
  `EV-9` la tarjeta de una inscripción es `services`, de la delegación y atada a ella, con
  la campaña «Pagos con tarjeta» (la de antes no existía y la tarjeta nunca había ido) (29/09).
- **Pasar Lista · monitores (26-27/09):** guardado sin «error», en tandas y en lotes
  (`set_entries`), motivo de las faltas, reuniones con su estado, monitores sin grupo
  vinculables desde la app. Detalle en `PASAR-LISTA-ESTADO.md` §1.
- **Frontend:** `UI-01..16` y la pasada del 24-25/09 (menú del móvil, portada, pestañas,
  márgenes, calendario, buscador).
- **Rendimiento:** `PERF-01..07` y plan 011 (caché de campos, sesión compartida, tandas,
  tope de página, assets condicionales).
- **Mantenimiento:** `MNT-01..02` · 13 funciones muertas y 2 páginas rotas fuera (25/09).
- **Datos del CRM (27/09, por MCP):** `CRM-01` el entorno de Solete ya es de MCM Castellón y
  no queda ninguno del «Administrador MCM» · `CRM-02` no queda ninguna asistencia `Unknown`.
  ⚠️ El id de Solete de `PASAR-LISTA-ESTADO.md` (`00000014…`) ya no existe: es
  `42e6c5d7-907b-4b86-8d46-a732beb570da`.
- **Documentación y CI:** `DOC-01..02` · `CI-01` deploy a producción · `tests.yml` en los PR.

> Mantén esta lista al día: al terminar, la tarea sale de arriba y entra aquí en una línea.
