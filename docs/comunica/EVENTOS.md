# Eventos e inscripciones — qué se pinta y quién puede apuntarse

Cómo se pintan los eventos (listado, ficha de detalle e inscripción) y, sobre
todo, **cómo se decide a qué eventos puede apuntarse cada persona**, que es la
parte nueva y la que más cuesta entender.

El formato vive en [`inc/stic-events.php`](../../inc/stic-events.php); la
audiencia, en [`inc/stic-event-audience.php`](../../inc/stic-event-audience.php).

> Los campos del CRM de este módulo están en
> [`CAMPOS.md`](CAMPOS.md) §1 → Eventos, que es **la fuente de la verdad**.
> Verificado contra el CRM por MCP el 09/09/2026.

---

## 1. Lo primero, porque cambia cómo se piensa todo lo demás

**Los grupos de seguridad del CRM no protegen el área privada.**

El área privada **no** se conecta al CRM como la persona que ha entrado: se
conecta con **un usuario técnico** (`SugarRestApiCall::login()`, con las
credenciales del plugin). Los grupos de seguridad de SuiteCRM —que sí separan
por delegación cuando un monitor entra en el CRM con su cuenta— **no filtran ni
una fila** de lo que lee el área privada.

Consecuencia práctica: **todo lo que no filtre el plugin, se ve.** Hasta el
09/09/2026 «Eventos» no filtraba nada, así que una madre de Reus veía —y podía
apuntarse a— la convivencia de Castellón, y los tres cursos de la ESO veían las
sesiones semanales del MIC, que son de 4.º, 5.º y 6.º de primaria.

La delegación se filtra en el plugin comparando `assigned_user_id`, igual que ya
hace Pasar Lista (`sticpa_pl_delegation()`). Es la misma regla de `CLAUDE.md`:
todo lo que se crea en el CRM va asignado a su delegación, y de ahí cuelga
todo lo demás.

---

## 2. Qué se usa HOY (campos que ya existen en `stic_Events`)

| Campo CRM | Dónde se ve | Notas |
|-----------|-------------|-------|
| `name` | Título de la tarjeta y de la ficha | Obligatorio: sin nombre el evento no se pinta |
| `start_date` | Cápsula de fecha + "del 1 al 10 de julio de 2026" | Sin fecha, la cápsula muestra un icono genérico |
| `end_date` | Rango de fechas y cálculo de duración | Si falta, se asume evento de un día |
| `status` | Chip de estado (etiqueta traducida del desplegable) | El valor crudo (`registration`…) no se muestra nunca |
| `description` | Bloque "Sobre esta actividad" de la ficha | Texto plano; los saltos de línea se respetan |
| `type` | Se recupera, aún sin uso visual | Reservado para agrupar/filtrar por tipo |
| `assigned_user_id` | No se ve | **La delegación del evento.** Decide quién puede apuntarse (§3) |
| `ajmcm_filtro_edades_c` | No se ve | **Los cursos a los que va dirigido.** Decide quién puede apuntarse (§3) |

**"Ya celebrado"** no es un campo: se calcula con `end_date` (o `start_date` si
no hay fin). Los eventos pasados se muestran apagados, al final de la lista y
sin botón de inscripción.

---

## 3. LA AUDIENCIA: quién puede apuntarse a qué

Tres ejes, y son tres a propósito. Se cumplen **todos a la vez** (es un Y).

```
1. ÁMBITO   ¿de quién es el evento?      → assigned_user_id + ajmcm_ambito_c
2. PERFIL   ¿a qué papel va dirigido?    → ajmcm_dirigido_a_c
3. CURSO    ¿de qué cursos escolares?    → ajmcm_filtro_edades_c
```

Los dos casos reales que hay que poder expresar:

| Caso | Ámbito | Perfil | Curso |
|---|---|---|---|
| Sesiones semanales del MIC de Castellón | local (asignado a Castellón) | *(vacío)* | 4.º, 5.º y 6.º de primaria |
| Congreso nacional de monitores | nacional | monitores | *(vacío)* |
| Encuentro de miembros COM-LC de la delegación | local | `grupo` (miembros del MCM) | *(vacío)* |

### 3.1 Por qué tres campos y no uno

Meter «monitores» y «4.º de primaria» en el mismo desplegable parece más
cómodo, y es la forma de acabar sin poder decir «los monitores de 4.º» ni
«cualquiera de 4.º». Son **dos preguntas distintas sobre la misma persona**: qué
papel tiene y en qué curso está.

Es el mismo error, en otra parte del CRM, que confundir `ajmcm_segmento_com_c`
(el segmento del grupo) con `ajmcm_nivel_com_c` (el itinerario personal): dos
ejes **sin ninguna correspondencia entre ellos**, y que solo se parecen en que
los dos usan números romanos.

### 3.2 De dónde sale el dato de la PERSONA

| Eje | De dónde | Coste |
|---|---|---|
| Delegación | `assigned_user_id` del contacto, ya en sesión (`sticpa_pl_delegation()`) | 0 llamadas |
| Perfil | `Contacts.stic_relationship_type_c`, ya en sesión (`scp_relationship_raw`) + los `relationship_type` de sus relaciones vigentes | 0 ó 1 llamada, cacheada |
| Curso | `stic_Contacts_Relationships.ajmcm_curso_escolar_c` de sus relaciones **de participante** vigentes | la misma llamada de arriba |

La llamada es `sticpa_pl_mis_rels()`, que **ya existía** para Pasar Lista: se le
añadieron dos campos (`active` y `ajmcm_curso_escolar_c`) a la misma petición.
Dos campos más en la misma llamada son gratis; una llamada nueva no. Y solo se
pide **si algún evento de la lista restringe** por perfil o por curso: un
listado sin restricciones no la paga.

⚠️ **El curso de una relación de MONITOR es el de SU GRUPO, no el suyo.** En los
datos reales hay monitoras adultas con `5_primaria` en su relación de monitora.
Por eso el curso se lee **solo de las relaciones de participante**
(`participante_mic_com` y `grupo`). Contarlo mal metería a una monitora en los
eventos de 5.º de primaria y —peor— la dejaría fuera de los de su edad.

⚠️ **Se comprueba el PERFIL ACTIVO, no quien inició sesión.** Cuando una madre
está viendo a su hija, la inscripción es de la hija (`scp_user_id` ya es la
hija), así que la audiencia que se comprueba es la de la hija. Es lo correcto,
y es gratis: la sesión ya lo tenía resuelto.

### 3.3 Qué se hace cuando NO SE SABE

Es la parte que decide hacia qué lado se falla, y hay dos formas de fallar mal:
esconder de más (el área parece rota: «no hay eventos») y esconder de menos
(alguien se apunta a lo que no le toca). La regla es la misma distinción que
salvó a los monitores de quedarse sin menú (`scp_role_resolved`): **un vacío
resuelto es un dato; un vacío por no haber podido preguntar, no.**

| Situación | Qué se hace | Por qué |
|---|---|---|
| No sabemos la delegación de quien mira | **No se esconde nada** | Un problema de sesión no puede vaciar la pantalla |
| El evento no tiene delegación | **No se esconde a nadie** | Es de todas |
| El CRM contestó: la persona no tiene ningún papel | **Sí se esconde** un evento restringido por perfil | Es un «no» de verdad |
| No se pudo preguntar por sus papeles | **No se esconde nada** | No es un «no», es un «no sé» |
| La persona no tiene curso escolar propio | **El filtro de cursos no se le aplica** | Un monitor no tiene curso: si se aplicara, los monitores del MIC quedarían fuera de las sesiones del MIC |
| El campo no existe todavía en el CRM | **No restringe** y ni se le pide al CRM | Se puede desplegar hoy y rellenar el CRM mañana |

### 3.4 Dónde se comprueba (cuatro sitios, y solo uno cuenta)

| Sitio | Qué hace | Papel |
|---|---|---|
| `pages/list_stic_events.php` | Quita de la lista lo que no es tuyo | Cortesía |
| `pages/single_stic_events.php` | Sin botón, y **explica el motivo** | Cortesía |
| `pages/single_stic_registrations.php` | No enseña el formulario | Cortesía |
| `inc/stic-action.php` → `prefix_admin_single_stic_registrations()` | **No crea la inscripción** | **EL guard** |

Los tres primeros son interfaz. El cuarto es el que importa: el endpoint de
guardado se alcanza con un POST y el id del evento en la mano. Si la
inscripción no debe existir, es ahí donde no se crea. Es la misma doctrina que
el guard anti-duplicado, que está justo al lado.

La ficha **explica** por qué no puedes en vez de esconder el evento: a una ficha
se llega por un enlace que alguien te pasa por WhatsApp, y un «no puedes» sin
motivo es la peor pantalla posible.

### 3.5 Qué cambia el día que esto entra (mirado en el CRM real)

Hay que saberlo antes de desplegar. Comprobado el 09/09/2026:

- En el CRM hay **5 eventos**, y **los cinco están asignados a la misma
  delegación** (la del piloto de Castellón).
- Las personas, en cambio, están repartidas entre **una decena de usuarios de
  delegación** distintos.

O sea que, en cuanto el filtro entra, **quien no es de esa delegación deja de
ver esos cinco eventos** y le sale el estado vacío («Cuando se abra la
inscripción de una actividad, aparecerá aquí»). Eso es lo correcto —esos
eventos no son suyos—, pero es un cambio visible: hasta ahora los veía todo el
mundo. Lo que hay que hacer no es tocar el filtro, es **que cada delegación
cree sus eventos** y los deje asignados a su usuario, como todo lo demás.

Si hiciera falta volver atrás mientras se ordena el CRM, se apaga sin
desplegar con el filtro de §3.6.

### 3.6 Cómo se apaga

```php
add_filter('sticpa_event_audience_enabled', '__return_false');   // todo
add_filter('sticpa_event_audience_field_perfiles', fn() => 'otro_campo_c');
add_filter('sticpa_event_audience_perfil_map', /* … */);         // el vocabulario
add_filter('sticpa_event_audience_non_delegation_users', fn() => array('1', '17'));
```

---

## 4. Campos que hay que CREAR en SinergiaCRM

**LA LISTA COMPLETA, y es la única que queda en todo el proyecto.** Verificado
contra el CRM el 10/09/2026: en Pasar Lista y en Coordinación no queda nada
pendiente (`PASAR-LISTA-CAMPOS-CRM.md` §2 y `PASAR-LISTA-COORDINACION.md` §6
tenían marcas de «por crear» obsoletas, ya corregidas).

Los cinco son de `stic_Events`. **Ninguno es obligatorio**, y mientras no
existan el área funciona igual: los campos que no están ni se le piden al CRM.

| # | Campo | Tipo | Para qué | Ficha |
|---|---|---|---|---|
| 1 | `ajmcm_lugar_c` | Texto (255) | El nombre del sitio. **El que más se nota** | §5.3 |
| 2 | `ajmcm_dirigido_a_c` | **Selección múltiple** | A qué perfiles va dirigido (monitores, grupo COM-LC…) | §4.1 |
| 3 | `ajmcm_ambito_c` | Desplegable | Local o de todas las delegaciones | §4.2 |
| 4 | `ajmcm_direccion_c` | Texto (255) | La dirección completa | §5.3 |
| 5 | `ajmcm_mapa_c` | URL | El enlace al mapa, solo cuando la búsqueda no acierta | §5.3 |

Están por orden de lo que aportan. **Si creas uno solo, el 1**: hoy nadie sabe
dónde es una actividad sin preguntar, y además enciende el botón del mapa él
solo. El 2 y el 3 son los que hacen falta para el congreso de monitores. El 4 y
el 5 son refinamientos del mapa.

> **`ajmcm_filtro_edades_c` NO está en la lista, y esto es lo bueno del asunto.**
> No solo existe y está relleno: **usa el MISMO desplegable
> (`ajmcm_curso_escolar_c_list`) que el campo de la persona**, no una copia. O
> sea que los dos lados del filtro de cursos comparten la lista y **no pueden
> divergir nunca**: quien añada un curso lo añade en los dos sitios a la vez.
> Nada que crear, nada que ampliar y nada que sincronizar. Ver §4.3.

---

### Los dos de la audiencia

Uno de ellos es opcional. El de los cursos **ya existía**.

### 4.1 `stic_Events` → `ajmcm_dirigido_a_c` 🔨

| | |
|---|---|
| **Módulo** | `stic_Events` (Eventos) |
| **Etiqueta** | Dirigido a |
| **Tipo** | **Selección múltiple** (`multienum`) |
| **Obligatorio** | No |
| **Por defecto** | *(vacío = para todos)* |

| Clave interna | Etiqueta |
|---|---|
| `grupo` | Miembros del MCM (con grupo) |
| `monitor` | Monitores/as |
| `participante_mic_com` | Participantes de MIC y COM |
| `coordinacion` | Equipo de coordinación |
| `familiar_menor` | Familias |

**Por qué múltiple:** un encuentro puede ser de monitores **y** de coordinación,
y con un desplegable simple habría que crear dos eventos.

**Por qué estas claves y no otras:** son **literalmente las de
`relationship_type`**, las que ya usa el CRM para decir qué es cada persona. No
es un vocabulario nuevo, y por eso el mapa
(`sticpa_event_audience_perfil_map()`) es casi la identidad: dos listas que
significan lo mismo acaban divergiendo, y una que no existe no. `coordinacion`
es el único agrupador —cubre `coordinacion_mic_com` y
`acompanamiento_mic_com`—.

⚠️ **«Miembros COM-LC» se dice con `grupo`, y no hay clave «laico».** Ser del
MCM es tener `grupo` —del COM o laico, la ficha es la misma—; encima puedes ser
monitor. El área ya tuvo un rol 'laico' que buscaba `com-lc`, `laic` y
`grupo com`, tres cadenas que no existen en este CRM, y que por tanto no se
disparó jamás. Ver [`CAMPOS.md`](CAMPOS.md) §2.

**Si se añade un valor nuevo al desplegable y se olvida el mapa**, no pasa nada
grave: una clave que no está en el mapa casa con el papel del mismo nombre.

### 4.2 `stic_Events` → `ajmcm_ambito_c` 🔨 *(opcional, pero recomendado)*

| | |
|---|---|
| **Módulo** | `stic_Events` (Eventos) |
| **Etiqueta** | Ámbito |
| **Tipo** | Desplegable (`enum`) |
| **Obligatorio** | No |
| **Por defecto** | *(vacío = se deduce de la delegación)* |

| Clave interna | Etiqueta |
|---|---|
| `local` | Solo su delegación |
| `nacional` | Todas las delegaciones |

**Para qué, si ya se deduce.** Vacío, el ámbito se saca de `assigned_user_id`:
con delegación es local, sin delegación (o asignado al «Administrador MCM») es
de todas. Eso cubre lo normal. El campo hace falta para el caso que no se puede
deducir: **un evento que organiza Castellón y que aun así es para todas las
delegaciones** —el congreso lo monta una delegación, pero va todo el mundo—.
Sin el campo habría que dejarlo sin asignar, y `CLAUDE.md` dice que todo va
asignado a su delegación por el grupo de seguridad.

### 4.3 `stic_Events` → `ajmcm_filtro_edades_c` ✅ **nada que tocar**

Está creado, **relleno** (las sesiones del MIC llevan
`^4_primaria^,^5_primaria^,^6_primaria^`) y —lo importante— **usa el mismo
desplegable que el campo de la persona**: `ajmcm_curso_escolar_c_list`.

```
stic_Events.ajmcm_filtro_edades_c            ┐
                                             ├── ajmcm_curso_escolar_c_list
stic_Contacts_Relationships.ajmcm_curso_escolar_c ┘
```

Eso es lo que hace que el filtro funcione y siga funcionando. Se comparan clave
a clave, y como **no son dos listas sino una**, no pueden divergir: el día que
alguien añada un curso, lo añade en los dos lados a la vez. (Si fueran dos
listas, el día que una guardara `1_eso` y la otra `eso_1` el filtro dejaría de
casar y nadie vería un error: simplemente todo el mundo pasaría el filtro.)

Dominio completo en [`CAMPOS.md`](CAMPOS.md) §1 → Relaciones con Personas:
`3_primaria` … `6_primaria`, `1_eso` … `4_eso`, `1_bachillerato`,
`2_bachillerato`, `fp_gm`, `fp_gs`, `universitario`, `otros`, `na`.

⚠️ **`na` [NA] no es un curso: es «no aplica».** Para el filtro cuenta como *no
tener curso*, no como un curso que no casa con ninguno — si contara como curso,
una persona marcada `na` quedaría fuera de cualquier evento que restrinja
cursos. `otros` sí es un curso, y casa solo con `otros`
(`sticpa_event_audience_cursos_no_aplica()`).

---

## 5. Los campos que se pintan, y los nombres que estaban mal

`sticpa_event_optional_fields()` declara los campos que la ficha pinta sola si
existen en el CRM. Pedía tres que **NO EXISTEN**, y que sí existen con otro
nombre: o sea que el aforo, el horario y la ventana de inscripción estaban
rellenos en el CRM, no se pintaban, y la lista invitaba a crear campos
duplicados. Corregido el 10/09/2026:

| Lo que el código pedía | Lo que hay de verdad en el CRM |
|---|---|
| `capacity` | **`max_attendees`** (entero) |
| `start_time` | **`timetable`** (texto libre con el horario) |
| `registration_end` | **`ajmcm_end_inscripcion_c`** + `ajmcm_start_inscripcion_c` (§5.2) |
| `price` | **`price`** ✅ coincidía |
| `location` / `city` / `address` | No existen. Se sustituyen por `ajmcm_lugar_c` y `ajmcm_direccion_c`, **por crear** (§5.3) |

### 5.1 Un 0 no es una respuesta

Los cinco eventos del CRM tienen `max_attendees = 0` y `price = 0.00`: es el
valor por defecto de SuiteCRM, no un dato. Sin tratarlo, la ficha decía:

- **«Plazas 0»**, que se lee como *no hay plazas* — exactamente lo contrario de
  *sin límite*, que es lo que significa;
- **«Precio 0,00 €»** en TODAS las actividades.

Y con el precio no se arregla poniendo «Gratis»: nadie ha dicho que sea gratis,
solo que el campo está sin rellenar, y equivocarse con el dinero es la peor
forma de equivocarse. Así que un 0 en estos dos campos **no se enseña**
(`'skip_zero' => true`).

Por lo mismo, **la duración no se cuenta si no es una duración**: «Sesiones
semanales 2026-2027» va de septiembre a junio y la ficha decía «Duración: 231
días». Es cierto y no significa nada — eso no es una actividad de 231 días, es
un curso. Por encima de un mes (`sticpa_event_max_dias_duracion`) el número es
ruido y se deja fuera; las fechas ya están en la cabecera.

### 5.2 La ventana de inscripción, que además cierra la inscripción

`ajmcm_start_inscripcion_c` y `ajmcm_end_inscripcion_c` existían desde antes
que esta pantalla y no se miraban. Ahora son un dato clave («Hasta el 25 de
octubre», «Se abre el 1 de octubre») **y deciden si se puede apuntar**, que es
justo lo que este documento llamaba «la forma limpia» de cerrar una inscripción
sin que nadie tenga que acordarse de cambiar el estado a mano.

| Estado | Cuándo | Qué pasa |
|---|---|---|
| `sin_datos` | los dos campos vacíos | **abierta** — ver la regla de abajo |
| `antes` | aún no se ha abierto | sin botón, chip «Inscripción no abierta» |
| `abierta` | dentro de plazo | botón, y la fecha límite a la vista |
| `cerrada` | el plazo terminó | sin botón, chip «Inscripción cerrada» |

**Regla de seguridad:** hoy solo **1 de los 5 eventos** del CRM tiene estas
fechas, así que el vacío cuenta como *abierta*. Tratarlo como cerrada dejaría
el área sin poder apuntarse a nada. Misma doctrina que la casilla de Pasar
Lista.

El día de fin **cuenta entero** (hasta las 23:59): un plazo «hasta el 25» que
cierra a las 00:00 del 25 le roba un día a la gente.

⚠️ **Y el chip lo dice, aunque el CRM diga otra cosa.** `status` está en
`registration` («Inscripción abierta») en los cinco eventos, lo pongan al día o
no. Sin esto, una tarjeta decía «INSCRIPCIÓN ABIERTA» y justo debajo «Cerrada
el 6 de septiembre»: es la clase de pantalla que hace que nadie se crea nada de
lo que pone. **Cuando hay fechas, mandan las fechas**
(`sticpa_event_registration_chip()`).

El bloqueo por fechas pasa por las MISMAS cuatro puertas que la audiencia
(§3.4), guardado incluido, y por la misma fuente única:
`sticpa_event_signup_block()`. Si las fechas del CRM estuvieran mal y hubiera
que desactivarlo:

```php
add_filter('sticpa_event_registration_window', function ($w) {
    return array('estado' => 'sin_datos', 'start_ts' => null, 'end_ts' => null, 'abierta' => true);
});
```

### 5.3 El lugar: tres campos por crear, y el botón del mapa

Es el dato que más se echa en falta. En el CRM existe un módulo de ubicaciones
(`stic_events_fp_event_locations`, una relación) y **se ha decidido no usarlo**:
para un puñado de eventos por delegación y curso, mantener un catálogo de
sitios es más trabajo del que ahorra, y además costaría una consulta más por
listado. El precio de decidirlo así es que «Casa de Espiritualidad» se acabará
escribiendo de cinco maneras, como ya pasa con `cursos_c`; se asume **porque
este dato se lee, y no se filtra ni se agrupa por él**.

#### `ajmcm_lugar_c` — Lugar (texto) 🔨

| | |
|---|---|
| **Módulo** | `stic_Events` |
| **Tipo** | Texto (255) |
| **Obligatorio** | No, pero es el que hay que rellenar siempre |

El **nombre corto** del sitio: «Casa de Espiritualidad, Benigànim». Sale en la
**tarjeta del listado** y en la ficha, así que conviene que quepa en una línea
de móvil.

#### `ajmcm_direccion_c` — Dirección (texto) 🔨

| | |
|---|---|
| **Módulo** | `stic_Events` |
| **Tipo** | Texto (255) |
| **Obligatorio** | No |

La **dirección completa**: «C/ Santiago 24, 28200 San Lorenzo de El Escorial».
Solo en la ficha, y es lo que se le manda al mapa (es más preciso que el
nombre). Son dos campos y no uno porque hacen dos cosas: uno cabe en la
tarjeta y el otro sirve para llegar.

#### `ajmcm_mapa_c` — Enlace del mapa (URL) 🔨 *(opcional de verdad)*

| | |
|---|---|
| **Módulo** | `stic_Events` |
| **Tipo** | URL (o texto) |
| **Obligatorio** | No |

**El botón del mapa NO necesita este campo.** Con el nombre del sitio ya se
arma una búsqueda de Google Maps, así que el botón funciona desde el primer día
con `ajmcm_lugar_c` relleno. Si hiciera falta pegar un enlace, no habría botón
hasta que alguien se acordara de hacerlo en cuarenta eventos.

Este campo es **el arreglo** para cuando la búsqueda no acierta —de «Casa de
Espiritualidad» hay unas cuantas— o cuando ya se tiene el enlace bueno. Si está
relleno, manda él.

```
ajmcm_mapa_c        →  se usa tal cual
si no, dirección    →  búsqueda de Google Maps
si no, lugar        →  búsqueda de Google Maps
si no hay nada      →  no hay botón
```

⚠️ **Solo se aceptan `http` y `https`.** El valor lo escribe una persona en el
CRM, así que quien tenga cuenta podría dejar un `javascript:…` en un enlace que
después pulsa una familia. Un esquema que no valga se descarta y se cae a la
búsqueda (`sticpa_record_safe_url()`).

#### Cómo se ve

El dato «Lugar» de la ficha **entero** es el enlace, no un icono al final: en un
móvil, 44px de alto por todo el ancho se acierta con el pulgar y un icono de
18px no. La flecha del final es la señal de que se puede tocar, no el objetivo.
Se abre en otra pestaña con `rel="noopener noreferrer"`, y el lector de pantalla
oye «Ver en el mapa: …», que una flecha sola no dice a dónde lleva.

No compite con «Inscribirme»: sigue habiendo **una sola acción principal** por
pantalla (design.md §6.2). Es un dato que además se puede tocar.

Esto es genérico, no de eventos: `sticpa_record_detail_html()` acepta
`'link' => array('url', 'label')` en cualquier dato clave, así que el día que un
pago quiera enlazar a su recibo, ya está.

> El plugin **pregunta primero al CRM qué campos existen**
> (`sticpa_event_fields_to_request()`), así que declarar aquí un campo que aún
> no está creado no rompe nada: simplemente no se pide ni se pinta. Eso vale
> también para los campos de audiencia.

---

## 6. Pantallas

| Pantalla | Archivo | Qué muestra |
|----------|---------|-------------|
| Listado | `pages/list_stic_events.php` | Tarjetas con fecha, nombre, lugar y estado. **Filtradas por audiencia**, y sin botón fuera de plazo. Próximos primero; los ya inscritos se ocultan (están en "Inscripciones") |
| Detalle | `pages/single_stic_events.php` | Ficha completa + botón de inscripción, o el motivo por el que no lo hay |
| Inscripción | `pages/single_stic_registrations.php` | Formulario con la tarjeta del evento arriba; o el aviso de que no es para ti |

El listado **ya no usa DataTables**: eran tres filas de "ETIQUETA: valor" por
evento y el buscador sobra con pocos eventos. Si algún día la lista crece
mucho, el sitio natural para un filtro (por tipo o por fecha) es
`sticpa_events_list_html()`.

---

## 7. Cómo se verifica la pantalla

Hay arnés de render offline, porque design.md §9 exige capturar a 375px y
mirarlo, y aquí no hay WordPress:

```bash
php tests/manual/render-events.php > /tmp/eventos.html
```

Pinta los cinco estados de la tarjeta, el estado vacío y las seis fichas
(completa, con ceros, con enlace de mapa propio, otra audiencia, fuera de plazo,
ya inscrito). Los meses
salen en inglés y **no es un fallo**: el doble de `date_i18n()` no tiene
idioma. Comprobado el 10/09/2026 en claro y en oscuro: sin scroll horizontal a
375px, ningún objetivo táctil por debajo de 44px y los chips por encima de
4,5:1 de contraste.

De hacer esa comprobación salieron dos arreglos que no eran de eventos:
`.stic-rec-btn` tenía `min-height: 42px` —el botón de las tarjetas y fichas de
los ocho módulos del área, incumpliendo la comprobación 5 de §9— y el chip
apagado usaba `--gray-500` sobre `--gray-100`, que da 4,39:1 cuando §3 exige
4,5. Ahora son 44px y `--gray-600` (6,87:1).

---

## 8. Qué NO hace falta

- **No hace falta un campo «abierto a inscripción»**: lo hacen `status` y las
  fechas de `ajmcm_start_inscripcion_c` / `ajmcm_end_inscripcion_c` (§5.2).
- **No hace falta un campo de audiencia en `Contacts`.** El papel de cada
  persona ya está en `stic_relationship_type_c` y en sus relaciones, y el curso
  en `ajmcm_curso_escolar_c` de la relación. Crear un «perfil para eventos» en
  la ficha de la persona sería una tercera fuente de verdad que alguien tendría
  que mantener a mano.
- **No hace falta tocar `target_audience`.** Existe (es de SinergiaCRM), está
  vacío en todos los eventos y es de selección simple. Se deja quieto: no se le
  mete nuestro vocabulario a un campo ajeno, y con uno solo no se puede decir
  «monitores Y coordinación».
- **No hace falta usar `ajmcm_etapa_c` para la audiencia** aunque sea el
  candidato natural. Dice a qué etapas sirve el evento **en Pasar Lista**, no a
  quién se le ofrece: un congreso de monitores marcado `^COM^` dejaría fuera a
  los monitores del MIC.
