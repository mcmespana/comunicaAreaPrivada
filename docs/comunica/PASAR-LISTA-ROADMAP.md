# Pasar Lista — fases y melones pendientes

El diseño de lo que se construye ahora está en
[`PASAR-LISTA.md`](PASAR-LISTA.md). Los campos del CRM, en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md).

Aquí va el orden de construcción y todo lo que sabemos que llegará pero **no se
hace ahora**, para que no se pierda ni contamine el diseño de la fase 1.

---

## Dónde estamos

> **El estado al día vive en
> [`PASAR-LISTA-ESTADO.md`](PASAR-LISTA-ESTADO.md)**: qué funciona, qué está
> roto y los IDs verificados del piloto. Lo de aquí abajo es el montaje inicial
> del CRM, que es otra cosa y no se actualiza cada semana.

**Piloto: MCM Castellón, curso 2025-2026.** Ya montado en el CRM:

| Qué | Detalle |
|---|---|
| `COM \| Sesiones semanales 2025-2026` | 24 sesiones · 36,5 h · sábados 16:30-18:00 |
| `MIC \| Sesiones semanales 2025-2026` | 23 sesiones · 34,5 h · sábados 16:30-18:00 |
| Grupo `C1` | Monitor David Soler · participantes Solete Vilarroya y Sol Meseguer |
| Inscripción de prueba | Solete Vilarroya en el COM, **79,45 %** de asistencia |
| Módulo `LIS_listas` | Creado y verificado |

Todo asignado al usuario **MCM Castellón**, que es lo que hace que el grupo de
seguridad de la delegación se aplique solo.

Las fechas salen del calendario real de la delegación (`Fiesta inicial` 18/10,
`Sesión MIC y COM` 01/11, `Cena Navidad COM` 22/12, `Sesión MIC` + `Subida Mgd
COM` 28/02, `Fiesta final` 02/05) y los sábados intermedios, quitando la
convivencia de Benigànim, Navidad, Magdalena y Sábado Santo.

---

## Fases

### Fase 0 — cerrar el CRM ⏳ casi

- [x] Módulo `LIS_listas`
- [x] Eventos, sesiones e inscripción de prueba del piloto
- [ ] Campo `ajmcm_segmento_com_c` en Grupos
- [x] Claves de `stic_Attendances.status`: `yes`, `partial`, `no_justified`,
      `no_unjustified`
- [ ] Confirmar las claves del desplegable `estado` de `LIS_listas`
- [x] El móvil del participante es `phone_mobile` (confirmado)
- [ ] Corregir `CAMPOS.md`: `phone_mobile` no es «No usar» — y subir el cambio
      al repo `comunicaFormularios`
- [ ] Crear el módulo `AVI_avisos` (spec en `PASAR-LISTA-CAMPOS-CRM.md` §6)
- [ ] Poner `code` a los grupos de Castellón que se vayan a usar
- [ ] Asignar grupo a los participantes y monitores del piloto

### Fase 1 — pasar lista ✅ hecha

Home con el atajo, árbol etapa → grupo, selector de sesión, pantalla de marcado,
guardado en lote y escritura en `LIS_listas`. Con esto ya se puede jubilar el
AppSheet en Castellón.

Archivos: `inc/stic-pasar-lista.php` (lógica, con tests),
`inc/stic-pasar-lista-crm.php` (consultas), `inc/stic-pasar-lista-ui.php` (HTML
compartido), `pages/single_stic_pasar_lista*.php`, `css/pasar-lista.css`,
`js/stic-pasar-lista.js`.

### Fase 2 — la ficha y la familia ✅ hecha

Ficha del participante con los teléfonos primero (chaval, familia,
emergencias), asistencia hasta hoy con denominador, salud en una tarjeta,
permisos y pañuelo editable con confirmación.

Falta de aquí: los **avisos de comportamiento**, que esperan a que exista
`AVI_avisos`. No se pinta el bloque en vacío a propósito: una sección que no
hace nada es peor que no enseñarla.

### Fase 3 — coordinación ✅ casi

Hecho: resumen con recuentos por etapa, la **tira de listas** por grupo (una
marca por sesión celebrada, que enseña de un vistazo qué días faltan) y «datos
por revisar» con los participantes sin grupo, que coordinación asigna desde ahí.

Pendiente:

- **Quién es coordinador/a.** `sticpa_pl_is_coordinator()` existe pero devuelve
  false mientras no haya dónde guardarlo (§7 de `PASAR-LISTA-CAMPOS-CRM.md`). El
  defecto es el correcto: sin ese dato se ve todo y no se edita nada.
- Recuentos por **segmento** COM I/II/III: esperan a `ajmcm_segmento_com_c`.
- Buscador de participantes.
- El aviso de ausencias seguidas en la **pantalla de marcado**. Hoy sale en la
  ficha, donde cuesta una consulta; en la lista costaría una por participante.
  Cuando el resumen recorra el curso, se puede alimentar de ahí.

### Fase 4 — sin conexión ✅ hecha

Se pasa lista en patios y sótanos. Son dos problemas distintos y se han
resuelto por separado:

**1. La cobertura se cae mientras marcas** (el caso real de un sábado). Activo
siempre, sin nada que encender:

- **Borrador.** Lo marcado se guarda en el móvil a cada toque. Si la app se
  cierra o se recarga, al volver está todo y la pantalla lo dice.
- **Cola de envío.** Si al guardar no hay red, el envío se guarda y se reintenta
  al recuperarla, a la misma URL y con los mismos campos que el formulario: no
  hay una segunda ruta de guardado que pueda desincronizarse. Una entrada por
  sesión y grupo, así que guardar dos veces sin cobertura manda la última, no
  las dos.
- **Aviso de estado** encima del botón: sin cobertura, guardado en el móvil,
  enviando lo pendiente, ya enviado.

**2. Abrir la pantalla ya sin cobertura.** Service worker, **encendido**. Si
alguna vez se pelea con la caché o el CDN de una instalación, se apaga sin tocar
código:

```php
add_filter('sticpa_pl_offline_enabled', '__return_false');
```

Se sirve en `/?sticpa_sw=1` (`inc/stic-pasar-lista-sw.php`) porque el alcance de
un service worker no puede ser más amplio que su carpeta, y el área privada está
fuera de la del plugin. Un parámetro no cambia la ruta, así que la ruta sigue
siendo `/` y el alcance es todo el sitio — sin reglas de reescritura que haya que
vaciar al activar.

Se deja apagable porque un service worker manda sobre **todas** las peticiones
del sitio, y esto se instala en WordPress que no controlamos (con sus cachés y
sus CDN). Con el punto 1 activo, apagarlo solo cuesta el arranque en frío sin
cobertura.

**Privacidad.** Lo que se guarda son pantallas con nombres, teléfonos y datos de
salud de menores, así que: la caché de páginas va **nombrada por usuario** (si en
el mismo móvil entra otra persona, no puede leer la anterior, y las cachés de
los demás se borran), y al cerrar sesión se borra todo. Solo se guardan las
pantallas de Pasar Lista, nunca un POST, nunca otra sección.

### Fase 5 — coordinación y monitores ✅ hecha

Ver [`PASAR-LISTA-COORDINACION.md`](PASAR-LISTA-COORDINACION.md) para el porqué
de cada decisión. Lo construido:

- **Alcance de coordinación** desde la relación `coordinacion_mic_com`: etapa,
  segmento o toda la delegación. Quien no tiene alcance marcado ve el conjunto.
- **Lista de monitores** con el defecto invertido (todos en verde) y el verde
  escrito de forma explícita al guardar.
- **Ficha del monitor**: certificado de delitos primero, asistencia con
  denominador, titulaciones con el aviso de «titulado y sin archivo», contacto.
  Sin familia y sin salud.
- **Reuniones de programación**: evento aparte, y coordinación las crea desde la
  pantalla con nombre, día, hora y duración.
- Las dos secciones nuevas van **debajo** de los grupos en la misma pantalla, no
  en una interfaz aparte: coordinación también tiene grupo.

Pendiente de campos que no existen todavía:

- `coordinacion_mic_com` en `relationship_type` → sin él, nadie coordina y todo
  se comporta como para un monitor (ver, no editar). Es el defecto seguro.
- `LIS_listas.ajmcm_tipo_c` → sin él, la lista de monitores de un sábado y la
  del grupo se pisarían. **Esto sí bloquea** la marca de «lista de monitores
  pasada»; las asistencias se guardan igual.
- `ajmcm_GRUPOS.ajmcm_segmento_com_c` → el alcance por segmento. Creado y
  **encendido**. Se deja el interruptor (`sticpa_pl_has_segmento`) porque pedir
  un campo que no existe rompe la consulta ENTERA y deja la pantalla en blanco:
  si una instancia se queda sin él, hay salida sin tocar código.
- `stic_Events.ajmcm_etapa_c` (selección múltiple) → a qué etapas sirve cada
  evento. Con él, un mismo evento puede ser de MIC y de COM. Sustituye a deducir
  la etapa del nombre, que era el punto débil que quedaba.

### Fase 6 — seguimientos y acompañamiento ✅ hecha

Ver [`PASAR-LISTA-SEGUIMIENTOS.md`](PASAR-LISTA-SEGUIMIENTOS.md).

### Fase 7 — más delegaciones

Se hará una **skill** para montar el curso de una delegación nueva (eventos,
sesiones desde su calendario, inscripciones). El piloto de Castellón es la
plantilla.

---

## Melones pendientes

### 🥇 El navegador de fichas: ver los datos sin pasar lista

**Pedido el 28/08/2026. Hay que hablarlo antes de tocar código.**

Hoy, para ver los datos de un chaval o de un monitor, hay que **«pagar el
precio» de entrar a pasar una lista**: portada → árbol de grupos → marcar →
flecha de la fila → ficha. La ficha ya tiene todo lo que hace falta —sustituye a
abrir el CRM— pero está enterrada detrás de un flujo que es para otra cosa.

Lo que se quiere: una pantalla que lleve **directamente a fichas y a listas de
personas**, sin pasar por marcar. Navegable por lo que tenga sentido —**por
grupos, alfabética, por cursos**— y para las dos poblaciones: niños y monitores.

**Lo que ya está resuelto y se reaprovecha tal cual** (esto es lo que hace que
sea barato):

- `sticpa_pl_all_relationships()` trae **toda** la gente de la delegación en una
  consulta, con su grupo, su papel y su vigencia. El navegador no necesita ni
  una consulta nueva: es re-presentar lo que ya se carga en cada pantalla.
- `sticpa_pl_contacts_bulk()` resuelve los datos de lista de mucha gente de una
  vez: nombre, iniciales, edad, móvil.
- Las dos fichas (`single_stic_pasar_lista_ficha` para participantes,
  `single_stic_pasar_lista_monitor` para monitores) ya existen, ya agrupan sus
  consultas en tandas y ya son la pantalla que sustituye al CRM.
- La lista de monitores (`single_stic_pasar_lista_monitores`) ya es medio
  navegador: alcance por etapa y buscador.
- `sticpa_pl_curso_rank()` y `sticpa_pl_sort_key()` ya ordenan por curso y por
  apellido.

**Lo que hay que decidir, y por eso está sin empezar:**

1. **¿Una pantalla o dos?** Niños y monitores juntos con un conmutador, o
   separados como ahora.
2. **¿Cuál es el orden por defecto?** Por grupo coincide con cómo se piensa un
   sábado; alfabético es lo que sirve cuando buscas a alguien y no recuerdas su
   grupo. Probablemente hacen falta los dos, pero uno tiene que ser el primero.
3. **¿Quién lo ve?** Un monitor solo su grupo, o toda la delegación — que es lo
   que ya puede hacer hoy pasando lista de cualquier grupo, así que restringirlo
   aquí sería incoherente.
4. **¿Entra en el menú principal** o cuelga de la portada de Pasar Lista?
5. **¿Y la ficha de un participante, sin grupo en la URL?** Hoy
   `single_stic_pasar_lista_ficha` exige `?grupo=` y comprueba que la persona
   esté en él. Desde un navegador alfabético puede no haber grupo de partida:
   hay que decidir si se deduce o si se hace opcional (y entonces cómo se
   comprueba que la persona es de la delegación).

**Ojo con lo que NO se puede romper:** el alcance sigue siendo la delegación, y
nada interdelegacional. El navegador no puede convertirse en la puerta por la
que alguien vea a gente de otra delegación.

### 0. Recuentos y nombres de monitor en la ficha del grupo

El árbol dice `MIC · 2025-2026` donde el diseño dice
`Mercedes · 1º ESO · 10 participantes`, y las tarjetas del resumen ponen de
número grande *cuántos grupos* hay en vez de *cuántos chavales*. No es un
descuido: saber cuánta gente hay en un grupo es una llamada a la API **por
grupo**, y en el árbol serían más de veinte cada sábado.

Tiene plan cerrado y con los pros y contras medidos, en su propio documento:
**[`PASAR-LISTA-RECUENTOS.md`](PASAR-LISTA-RECUENTOS.md)**. En corto: cuatro
campos en `ajmcm_GRUPOS` (que entran gratis en la consulta que ya se hace) y un
script nuestro por API que los rellene de noche. Lo primero que hay que
averiguar es si hay acceso a ficheros de la instancia de SinergiaCRM, porque eso
decide el camino.

### 1. Pasar lista de un evento, no de una sesión semanal

> «Me voy de convivencia, tengo inscripciones hechas y quiero pasar lista del
> bus.»

Es un caso distinto al semanal y hay que hacerlo. Lo que lo diferencia:

- **El grupo no manda.** En una convivencia van chavales de varios grupos, y lo
  que quieres controlar es *quién se ha subido al bus*, no quién ha venido a su
  grupo.
- **La lista es la de inscritos al evento**, que es un dato que ya existe
  (`stic_Registrations` del evento).
- **Puede haber varias «listas» en el mismo evento**: la del bus de ida, la de
  la llegada, la de la vuelta. No es una por sesión semanal.

Primera intuición de modelo, para no perderla: una convivencia es un evento con
**sesiones** («Salida del bus», «Llegada», «Vuelta») y se pasa lista por sesión
igual que en el semanal, pero la lista no se filtra por grupo sino por *todos
los inscritos*. Si eso es así, `LIS_listas` sirve dejando el grupo vacío, y la
pantalla de marcado es la misma con otra fuente de participantes. Habría que
comprobar que el módulo admite `grupo` vacío.

Sin cerrar. Cuando toque, se piensa bien.

### 2. Ausencias de monitores

Extender el sistema para registrar qué monitores no han venido y sacar su
porcentaje de ausencias.

- Solo **coordinación** entra aquí. Un monitor no ve la lista de monitores.
- Los monitores de un grupo ya se sacan de las relaciones, así que la lista
  existe; falta dónde guardar su asistencia.
- Ojo: es un dato sensible entre compañeros. Merece pensar el encuadre antes que
  la técnica.

### 3. Inscripciones automáticas

Hoy la inscripción al evento de la etapa hay que crearla. Lo que se quiere:
**que al apuntarse a un grupo, la inscripción al evento de su etapa se cree
sola.** Vías: flujo de trabajo del CRM en el `after_save` de la relación con
persona, o hacerlo desde el área privada al inscribirse. Pendiente de decidir.

### 4. Grupos viejos y navegación

Hay ~150 grupos en el CRM, muchos históricos. Los que van a usar Pasar Lista de
verdad son bastantes menos:

| | Grupos | Niños |
|---|---|---|
| 3-4 delegaciones grandes | ~10 MIC + ~10 COM cada una | 10-12 por grupo |
| Las otras ~8 delegaciones | ~25 % de eso | igual |

Los grupos sin evento ni asistencias no deberían aparecer en la navegación de
Pasar Lista. Queda decidir si se ocultan por no tener evento asociado o hace
falta marcarlos de alguna forma.

### 5. El flujo de trabajo de correo de los avisos

Cuando exista `AVI_avisos`, **hay que crear un flujo de trabajo en SuiteCRM**
sobre ese módulo: al crear un aviso, correo a coordinación de la delegación. Es
configuración del CRM, no código del área privada, pero sin él un aviso se queda
solo en la ficha y nadie se entera.

Depende de tener a los coordinadores en algún sitio
(`PASAR-LISTA-CAMPOS-CRM.md` §7: se recomienda `stic_Contacts_Relationships` con
`relationship_type = coordinador_mic` / `coordinador_com`).

### 6. Qué es un «sector»

Hablamos de coordinadores de etapa y de sector. En el CRM no hay hoy nada que
agrupe delegaciones en sectores. Si un sector es un conjunto de delegaciones, va
en `Accounts`, no en la persona. Pendiente de definir qué es.

### 7. Un campo de etapa en los eventos ✅ resuelto

La etapa de un evento se sacaba **del nombre** (`COM | Sesiones semanales
2025-2026`), mirando solo lo que hay antes del `|`. Funcionaba porque la
convención estaba fija, pero un evento mal nombrado desaparecía de Pasar Lista
sin decir por qué. Era la única dependencia frágil de la fase 1.

Ahora manda `stic_Events.ajmcm_etapa_c`, de **selección múltiple** con las mismas
claves que el campo de la persona (`MIC` / `COM` / `LC`). Que sea múltiple es lo
que permite UN evento para MIC y COM a la vez, que es el caso normal cuando el
sábado es el mismo para todos: aparece en las dos etapas y comparte sesiones.

El nombre sigue siendo la red de seguridad, solo para eventos con el campo vacío,
y el prefijo se puede cambiar con el filtro `sticpa_pl_etapa_prefixes`.

### 8. Seguimientos de monitores ✅ hecho

Ver [`PASAR-LISTA-SEGUIMIENTOS.md`](PASAR-LISTA-SEGUIMIENTOS.md). Resumen: se usa
`stic_FollowUps`, el módulo **Seguimientos** que SinergiaCRM ya trae — ni `Notes`
modificado ni un módulo nuevo. Los tres tipos (incidencia, valoración de
trimestre, acompañamiento) son tres valores de su lista `type`.

Viene **encendido** (`sticpa_pl_seguimientos_enabled`). Los nombres de campo del
módulo son la convención documentada y **no los pude verificar contra la
instancia** (respondía «does not have access to this module»); si algo sale vacío
o el guardado falla, se corrige con el filtro `sticpa_pl_seg_map` y, mientras se
averigua, se apaga con `add_filter('sticpa_pl_seguimientos_enabled',
'__return_false')`.

### 9. Verificar `CAMPOS.md` contra el CRM por MCP

Algún día. Hoy `CAMPOS.md` es la fuente de la verdad y se mantiene a mano.

---

## Convenciones que ya podemos fijar

Como el sistema aún no está en uso real, podemos imponer convenciones en vez de
adaptarnos al desorden actual:

- **Todo grupo que pase lista tiene `code`.** Corto: `C1`, `M4`. Es lo que se
  lee en pantalla.
- **Nombre del grupo distinto del código.** Si el `code` y el `name` son lo
  mismo, la pantalla enseña solo uno (ver §6.2 del diseño).
- **Un evento por delegación y etapa y curso**, nombrado sin la delegación
  delante: `COM | Sesiones semanales 2025-2026`. La delegación ya está en el
  «asignado a». Las familias ven este nombre, así que se cuida.
- **Las relaciones persona-grupo de los menores se rehacen cada curso.** A
  partir de 3º de ESO el grupo suele mantenerse estable. Crearlas cada año no es
  asunto de esta pantalla, pero la pantalla debe asumirlo (por eso el histórico
  se apoya en la vigencia de la relación).
