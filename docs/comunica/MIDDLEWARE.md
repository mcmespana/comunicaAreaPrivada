# Pasar Lista por el middleware (comunicaMiddleware)

> Escrito el 29/10/2026 (fase 1 del plan de `comunicaMiddleware`, pasos 1a y 1b; del 1c al 1e, el 30/10: la fase entera,
> en `main`). El código está en [`inc/stic-middleware.php`](../../inc/stic-middleware.php); las consultas, en
> `comunicaMiddleware:src/reads/` (`pasar-lista.ts`, `ficha.ts`, `coordinacion.ts`, `eventos.ts`).

## Qué es

`comunicaMiddleware` (Vercel, `fra1`) lee la **base de datos del CRM** por SQL y aplica él el filtro por delegación y
papel (la base no tiene grupos de seguridad). Las pantallas de Pasar Lista le piden sus lecturas en vez de pedirlas a la
API v4.1; **si no contesta, siguen por la API como siempre** (decisión `plan-b`). Escribir sigue igual, por la API.

Medido en la base real el 29/10/2026: las 581 relaciones de Castellón, sus 19 listas o las 216 asistencias de su evento
COM salen en décimas de segundo. Por la API, la portada eran ~9 llamadas en 5 esperas.

## Qué va por el middleware

| Cargador | Recurso | Paso |
|---|---|---|
| `sticpa_pl_groups` | `groups` | 1a |
| `sticpa_pl_all_relationships_raw` | `relations` | 1a |
| `sticpa_pl_mis_rels` (solo las de quien ha entrado) | `myRels` | 1a |
| `sticpa_pl_events_raw` | `events` | 1a |
| `sticpa_pl_event_sessions` | `eventSessions` | 1a |
| `sticpa_pl_listas_index` | `listas` | 1a |
| `sticpa_pl_event_registrations` | `eventRegistrations` | 1b |
| `sticpa_pl_session_attendances` (también la relectura de después de guardar) | `sessionAttendances` | 1b |
| `sticpa_pl_attendances_for_sessions` (y con ella las rachas) | `attendancesForSessions` | 1b |
| `sticpa_pl_ficha` (**salud y DNI**: por `/plugin/v1/sensible`) | `ficha` | 1c |
| `sticpa_pl_family_fetch` (los dos sentidos del entorno personal, y los familiares) | `familyRels`, `familyContacts` | 1c |
| `sticpa_pl_avisos` | `avisos` | 1c |
| `sticpa_pl_contact_attendance` (también la de un monitor en su ficha) | `registrationAttendances` | 1c |
| `sticpa_pl_ev_de_persona` (antes 1 + 2 consultas por inscripción) | `personEvents` | 1c |
| `sticpa_pl_monitor_ficha` (**DNI**: por `/plugin/v1/sensible`) | `monitorFicha` | 1d |
| `sticpa_pl_seguimientos` (el filtro por papel, en el middleware; el del plugin se aplica igual) | `seguimientos` | 1d |
| `sticpa_pl_ev_inscripciones` (pasar lista en un evento: el autobús) | `evInscripciones` | 1e |
| `sticpa_pl_ev_personas` (con el evento) | `evPersonas` | 1e |
| `sticpa_pl_ev_rels_de` (antes un `mis_rels` por inscrito) | `evRelaciones` | 1e |
| `sticpa_gac_grupos`, `sticpa_gac_miembros` | `gacGrupos`, `gacMiembros` | 1e |

Lo de la ficha solo sale si la persona tiene una relación de participante o monitor con la delegación de trabajo (la
regla de `sticpa_pl_persona_de_mi_delegacion()`); si no, el middleware dice 403 y el plugin lo lee por la API, que hace
sus propias comprobaciones. **Los avisos, solo los de la delegación**: por la API salían también los de otra.

La lista de monitores de coordinación (`sticpa_pl_coord_monitors`) no tiene consulta propia: sale de los grupos y las
relaciones. La ficha del monitor y sus seguimientos solo salen a quien **coordina o acompaña en la delegación por una
relación vigente** (la marca de la ficha no basta, igual que en `sticpa_pl_coord_scope` y `sticpa_pl_is_acompanante`) y
de un monitor de la delegación. De los seguimientos, solo los de la delegación y de los tipos que ese papel puede leer
(los de acompañamiento, solo quien acompaña), y nunca los de uno mismo. Con las claves de tipo cambiadas por filtro
(`sticpa_pl_seg_type_keys`), los seguimientos siguen por la API.

En un evento (paso 1e), todo es de un evento de la delegación. Sus inscritos pueden ser de otra: de ellos salen su
nombre, su delegación y sus relaciones (solo participante, monitor y acompañamiento), que es de donde la lista saca su
curso. Antes era una consulta a la API por inscrito; ahora es una sola lectura. La pantalla del evento son 2 peticiones al
middleware y ninguna al CRM, también la pestaña de grupos de actividad.

Con esto está toda la parte de monitores de la fase 1. Siguen por la API las escrituras (todas) y lo de familias
(inscribirse, pagos), que no es de esta fase.

**Cómo encaja sin tocar las pantallas.** El middleware devuelve las filas de la MISMA consulta que hace cada cargador,
con los nombres de campo de la API; `sticpa_mw_rows()` les da la forma de `getRecordsModule()` y el cargador sigue con su
código de siempre (ordenar, marcas, vigencia, caché). Las filas que vienen de la base llevan `->mw = true`: sus enlaces
ya vienen resueltos en el campo plano y no se pregunta al CRM por ellos. Los respaldos de la API (las consultas «por si el
CRM no ha digerido el filtro») no corren con filas de la base.

**Las tandas.** En `sticpa_pl_prime()` la recolecta apunta lo que va al middleware y sale UNA petición
(`POST /plugin/v1/lote`) por tanda. La portada son 2 peticiones; marcar, 3; Mis grupos, 1 (`tests/MiddlewareTest.php`
vigila los topes). Si el lote falla entero, la tanda se recolecta otra vez para la API.

**La salud** de las marcas de la lista (alergias, intolerancias, tratamientos) llega solo como «hay algo escrito»: el
texto no sale de la base. La ficha sí la lleva (es donde se enseña), y por eso va por su propia ruta,
`POST /plugin/v1/sensible` (`sticpa_mw_sensibles()`), con `no-store, private`; el middleware rechaza la ficha por el
lote de siempre y cualquier otra cosa por esa ruta. En la ficha, esa petición sale aparte dentro de la misma tanda: son
5 peticiones al middleware y ninguna al CRM (antes, 13 viajes al CRM).

## Cómo se enciende

En `wp-config.php`:

```php
define('STICPA_MIDDLEWARE_URL', 'https://comunica-middleware.vercel.app');
define('STICPA_MIDDLEWARE_SECRET', '…');        // el mismo valor que MW_PLUGIN_SECRET en Vercel (32+ caracteres)
define('STICPA_MIDDLEWARE_MODO', 'admins');     // off | admins | todos
define('STICPA_MIDDLEWARE_ADMINS', 'id1,id2');  // ids de contacto que lo prueban (opcional)
```

- Sin URL o sin secreto (o con un secreto corto) **no hace nada**.
- **`admins`** (por defecto): solo quien entra con un usuario de WordPress administrador («Entrar como…» desde los
  ajustes del plugin) o un contacto de `STICPA_MIDDLEWARE_ADMINS`. **`todos`**: todo el mundo. **`off`**: nadie.
  Sin la constante, manda la opción `sticpa_mw_modo` (y `sticpa_mw_admins`).
- Interruptores sin desplegar: en Vercel, `MW_OFF=1` apaga el middleware entero y `MW_OFF_RECURSOS=relations,listas`
  apaga recursos sueltos; los dos hacen que el plugin lea por la API. En el plugin, el filtro
  `sticpa_mw_usar($usar, $recurso)` apaga un recurso.

**Si se cae**: tope de 4 s (1,5 s para conectar). Un fallo de red, un 5xx o un 401 lo dejan **en pausa un minuto**
(transient `sticpa_mw_caido`) para que nadie espere el tope en cada pantalla; un 403 o 422 es de esa persona y solo
afecta a su petición. Lo apunta en el registro de errores (`[sticpa] middleware: …`), sin datos de nadie.

## Cachés con el middleware

Decisión `cache-plugin` (la recomendada, pendiente de confirmar): con el middleware encendido, la **estructura** (grupos,
personas, eventos, sesiones, inscripciones) se cachea **1 h** en vez de 24 h, y el **estado** (listas, asistencias,
rachas) **30 s** en vez de 5 min: lo justo para no repetir la misma lectura al pintar una pantalla. Guardar tira la
caché igual que antes, y olvida las respuestas traídas en la petición (`sticpa_mw_forget()`).

**El botón de refrescar** (el icono a la derecha de la cabecera) tira la caché de la delegación antes de leer
(`sticpa_pl_maybe_refresh()`). Está en todas las pantallas de monitores y coordinación (portada, árbol de grupos, Mis
Grupos, las dos fichas, coordinación, resumen, reuniones) **menos en las dos donde se marca** (la lista de un grupo y la
de monitores): ahí borraría lo marcado sin guardar, y su estado ya se relee a los 30 s.

## Para administradores

- **La etiqueta** de la esquina de abajo a la izquierda: «SQL · 2 pet. · 340 ms» (discreta) si todo ha salido del
  middleware; «API · relations» (clara, en ámbar) si algo ha vuelto a la API, con qué; «caché» si no se ha leído nada.
- **`?mw=0`** en cualquier pantalla: la misma pantalla por la API, para comparar a ojo.
- **`?mw_comparar=1`**: al pie, cada lectura de la fase 1 hecha por los dos lados sin caché, con sus tiempos y las
  diferencias (rutas con ids y la **forma** de los valores: 9 = dígito, a = letra; nunca datos de personas). En la ficha
  de un participante (`…&participante=<id>`) compara también su ficha, familia, avisos, eventos y asistencias; en la de un
monitor (`…&monitor=<id>`), su ficha y sus seguimientos; en la de un evento (`…&evento=<id>`), sus inscripciones, sus
personas, la lista armada y sus grupos de actividad. Es lento
  (lee todo por la API) y es temporal, como «Pruebas BBDD» de Comunica Tools.

## Plan para encenderlo

1. Propietario: enlazar `CRM_BBDD_*` al proyecto `comunica-middleware` de Vercel, crear `MW_PLUGIN_SECRET` (Production) y
   poner las constantes de arriba con `STICPA_MIDDLEWARE_MODO = 'admins'`.
2. Un administrador abre las pantallas con `?mw_comparar=1` en una o dos delegaciones, y la ficha de dos o tres chavales.
   Diferencias esperables: ninguna en ids; en formato, alguna fecha de un enlace sin la hora (`birthdate`), que el plugin
   no usa más que como día. Si salen avisos «solo por la API», son de otra delegación (por el middleware no salen, a
   propósito) o de un enlace borrado: en Castellón, 7 de sus 8 avisos tienen el enlace a la persona borrado (30/10/2026).
3. Si sale limpio, `STICPA_MIDDLEWARE_MODO = 'todos'`. Si algo va mal el sábado: `'off'` en `wp-config.php` o `MW_OFF=1`
   en Vercel, y todo vuelve a la API sin desplegar.
