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
| Encuentro de miembros COM-LC de la delegación | local | miembros con grupo COM-LC | *(vacío)* |

### 3.1 Por qué tres campos y no uno

Meter «monitores» y «4.º de primaria» en el mismo desplegable parece más
cómodo, y es la forma de acabar sin poder decir «los monitores de 4.º» ni
«cualquiera de 4.º». Son **dos preguntas distintas sobre la misma persona**: qué
papel tiene y en qué curso está. Es el mismo error que separa
`ajmcm_segmento_com_c` (del grupo) de `ajmcm_nivel_com_c` (de la persona).

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

Solo dos, y uno de ellos es opcional. El de los cursos **ya existía**.

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
| `participante` | Participantes de MIC y COM |
| `grupo_com_lc` | Miembros con grupo COM-LC |
| `monitor` | Monitores/as |
| `coordinacion` | Equipo de coordinación |
| `familia` | Familias |

**Por qué múltiple:** un encuentro puede ser de monitores **y** de coordinación,
y con un desplegable simple habría que crear dos eventos.

**Por qué estas claves:** son **las de `relationship_type`** (las que ya usa el
CRM para decir qué es cada persona), no un vocabulario nuevo. Así no hay dos
listas que signifiquen lo mismo y puedan divergir. `coordinacion` cubre
`coordinacion_mic_com` y `acompanamiento_mic_com`; el mapa está en
`sticpa_event_audience_perfil_map()`.

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

### 4.3 `stic_Events` → `ajmcm_filtro_edades_c` ✅ **ya existe: solo ampliarlo**

Está creado y **relleno** (las sesiones del MIC llevan
`^4_primaria^,^5_primaria^,^6_primaria^`). Lo único que falta es **igualar su
dominio al de la persona**: hoy le faltan dos claves que sí tiene
`stic_Contacts_Relationships.ajmcm_curso_escolar_c`.

| Clave a añadir | Etiqueta |
|---|---|
| `universitario` | Universitarios |
| `otros` | Otros |

Sin ellas no se puede sacar un evento dirigido a universitarios, que son unos
cuantos en los grupos del COM.

> ⚠️ **Los dos campos tienen que usar LAS MISMAS CLAVES.** Se comparan clave a
> clave. El día que uno guarde `1_eso` y el otro `eso_1`, el filtro dejará de
> casar y nadie verá un error: simplemente todo el mundo pasará el filtro.

---

## 5. Campos que el área ya sabría pintar (y una corrección)

`sticpa_event_optional_fields()` declara siete campos que la ficha pinta sola
si existen en el CRM. **Y varios de ellos existen con OTRO nombre**, así que
hoy no salen aunque el dato esté (comprobado el 09/09/2026):

| Lo que el código busca | Lo que hay de verdad en el CRM |
|---|---|
| `capacity` | **`max_attendees`** (entero) |
| `registration_end` | **`ajmcm_end_inscripcion_c`** (y su pareja `ajmcm_start_inscripcion_c`) |
| `start_time` | **`timetable`** (texto libre con el horario) |
| `price` | **`price`** ✅ coincide |
| `location` / `city` / `address` | No existen: el lugar es una **relación** al módulo de ubicaciones (`stic_events_fp_event_locations`) |

**Pendiente, y no está hecho:** cambiar esos tres nombres en
`sticpa_event_optional_fields()` para que el aforo, la ventana de inscripción y
el horario salgan solos. Es una línea por campo y no toca ninguna otra lógica,
pero **cambia lo que se ve en todas las tarjetas y fichas**, así que pasa por la
verificación de pantalla de [`design.md`](../../design.md) §9-10. Se deja
anotado aquí y no se cuela con el cambio de audiencia.

El lugar es el dato que más se echa en falta y **es una relación**, no un texto:
pintarlo cuesta una consulta más y hay que decidir dónde (probablemente en la
misma llamada del listado, con `link_name_to_fields_array`).

> El plugin **pregunta primero al CRM qué campos existen**
> (`sticpa_event_fields_to_request()`), así que declarar aquí un campo que aún
> no está creado no rompe nada: simplemente no se pide ni se pinta. Eso vale
> también para los campos de audiencia.

---

## 6. Pantallas

| Pantalla | Archivo | Qué muestra |
|----------|---------|-------------|
| Listado | `pages/list_stic_events.php` | Tarjetas con fecha, nombre, lugar y estado. **Filtradas por audiencia.** Próximos primero; los ya inscritos se ocultan (están en "Inscripciones") |
| Detalle | `pages/single_stic_events.php` | Ficha completa + botón de inscripción, o el motivo por el que no lo hay |
| Inscripción | `pages/single_stic_registrations.php` | Formulario con la tarjeta del evento arriba; o el aviso de que no es para ti |

El listado **ya no usa DataTables**: eran tres filas de "ETIQUETA: valor" por
evento y el buscador sobra con pocos eventos. Si algún día la lista crece
mucho, el sitio natural para un filtro (por tipo o por fecha) es
`sticpa_events_list_html()`.

---

## 7. Qué NO hace falta

- **No hace falta un campo «abierto a inscripción»**: se deduce de `status` +
  fechas, y `ajmcm_end_inscripcion_c` ya existe para el control fino.
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
