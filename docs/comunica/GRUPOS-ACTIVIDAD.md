# Grupos de actividad en el área privada — patrullas, habitaciones, dinámica…

> **Estado (16/10/2026):** 🟡 hecho en el plugin. **El módulo ya existe en el CRM** y los nombres están comprobados
> ([`CAMPOS.md`](CAMPOS.md) §«Grupos de actividad»): persona e inscripción son relaciones muchos a muchos y se leen solo por
> el enlace. Falta verlo con grupos de verdad, cuando Comunica Tools suba los primeros.

Los grupos de una actividad (patrullas, habitaciones, grupos de dinámica, olimpiadas…) **se crean y se reparten en Comunica
Tools**, en escritorio y arrastrando (su `docs/GRUPOS-ACTIVIDAD.md`). El área privada es **donde se consultan**, en el
móvil, en el momento: en el autobús, en la casa de colonias, en mitad del monte. Aquí solo se leen.

## Dónde están: en la pantalla del evento, como pestañas

```
Pasar lista (portada)  ──  sin cambios: ni una llamada más los sábados
   └─ «Hoy empieza · Convivencia» / «Pasar lista en un evento»
         └─ El evento:   [ Lista ]  [ Patrullas 25 ]  [ Habitaciones 30 ]  [ Dinámica 8 ]
              · Lista ............ la del autobús, la de siempre (por etapa y curso)
              · Patrullas ........ los grupos, para mirar (y buscar «¿dónde está Lucía?»)
                   └─ «Pasar lista por patrullas» / «Pasar lista de este grupo»
                        → la MISMA lista de marcar, partida por patrullas, con chips por patrulla
```

### Por qué ahí y no en una sección nueva

| Opción | Por qué no / por qué sí |
|---|---|
| Sección nueva «Eventos y actividades» en el menú | Un mosaico más en el menú del móvil, y al lado de «Eventos» (el de apuntarse), que confunde. Y el camino sería igual de largo: menú → evento → grupos. |
| Dentro de «Grupos y fichas» | Esa pantalla es de los grupos de los sábados (`ajmcm_GRUPOS`). Mezclar dos clases de grupo es lo que la volvió un jaleo en septiembre. |
| **Pestañas en la pantalla del evento de Pasar lista** ✅ | El evento ya es «la cosa» de la que cuelgan los grupos de actividad, y la portada de Pasar lista ya ofrece **arriba del todo la tarjeta del evento de hoy**: dos toques desde que abres la app (tarjeta → pestaña). Sin menú nuevo, sin pantalla nueva que aprender. |

### Rapidez: lo que cuesta cada cosa

| Pantalla | Antes | Ahora |
|---|---|---|
| Portada de Pasar lista (la de todos los sábados) | — | **Igual. Cero llamadas nuevas** (lo fija `test_gac_la_portada_no_los_pide`). |
| Lista del evento (el autobús) | 2 tandas paralelas | Igual: la consulta de grupos (`GAC_grupos`, para saber qué pestañas hay) va **dentro de la primera tanda**, en paralelo con las inscripciones. Los miembros no se piden. Caché de 5 minutos. |
| Pestaña de grupos | — | 2 tandas: la primera con grupos y miembros (y sin sesiones ni asistencias, que aquí no hacen falta), la segunda con los nombres. |
| Módulo aún no creado | — | Una consulta fallida y se recuerda una hora (familia de estructura: el botón de refrescar lo olvida). |

## Qué se ve en una pestaña

- Una cabecera por grupo: **nombre** y cuántos (en habitaciones, «7/8 plazas»; si se pasa, en naranja).
- **Monitores primero** («Monitor/a» debajo del nombre), también los que **no se inscribieron** (salen con el nombre de su
  ficha de miembro). Después los participantes, por apellidos, con su curso y su grupo de los sábados («1 ESO · C1»).
- Quien tiene grupo de los sábados en la delegación, **enlace a su ficha** (teléfonos de casa, salud): lo que se busca en
  una acampada. Quien no, fila quieta.
- Inscripciones canceladas que seguían colocadas: salen con «Cancelada».
- Al final, **«Sin patrulla» / «Sin habitación» / «Sin grupo»**: quien va al evento y no tiene grupo en esa pestaña.
- **Buscador**: «Lucía» deja solo a Lucía bajo el título de su grupo; «patrulla 3» deja esa patrulla.
- Evento terminado: aviso «así quedaron los grupos».

Qué grupos salen: los de **la delegación dueña del evento** (el plugin entra con un usuario técnico y los grupos de
seguridad no filtran: se compara `assigned_user_id` a mano) y de **ese evento** (`evento_id`). Los **anulados**
(`activo` = 0) no salen; pero cuando el evento acaba, el flujo nocturno desmarca todos, así que en un evento pasado salen
los que tienen a alguien dentro (un anulado casi siempre está vacío).

## Pasar lista por grupos

«Pasar lista por patrullas» (o «de este grupo») abre **la misma lista de marcar del evento** (`&por=<pestaña>`, y
`&grupo=<id>` para empezar con uno elegido): mismas filas, mismo gesto, mismo «Están todos» (solo lo que se ve), misma cola
sin cobertura y misma comprobación al releer. Cambia solo cómo se parte: una sección por grupo y chips por grupo en vez de
por curso. «Ver por cursos» vuelve a la de siempre.

- Los **monitores sin inscripción** no se pueden marcar (una asistencia cuelga de una inscripción): salen en la cabecera
  de su grupo, «Sin inscripción, no se marca: …».
- ⚠️ **Es la misma lista del día.** Un evento tiene una sesión por día y la asistencia es una por persona y sesión: si el
  mismo día se pasa lista en el autobús y luego por habitaciones, es la misma lista y **la última marca manda**. Lo no
  marcado no se toca. Si algún día hace falta «asistencia por actividad» (la dinámica de la tarde, aparte del autobús),
  serían sesiones distintas en el CRM; no está hecho.

## Código y pruebas

| Pieza | Dónde |
|---|---|
| Nombres del CRM (en un solo sitio), lectura, lógica pura y pintado | [`inc/stic-grupos-actividad.php`](../../inc/stic-grupos-actividad.php) (`sticpa_gac_*`) |
| Las pestañas y la lista por grupos | [`pages/single_stic_pasar_lista_evento.php`](../../pages/single_stic_pasar_lista_evento.php) |
| Chip que llega apretado | [`js/stic-pasar-lista.js`](../../js/stic-pasar-lista.js), bloque «Lista de un evento» |
| Estilos | [`css/pasar-lista.css`](../../css/pasar-lista.css), sección «Grupos de ACTIVIDAD» |
| Tests | `tests/PasarListaRenderTest.php`, las `test_gac_*` (el CRM falso lleva `$conGac` y `$gacAusente`) |
| Arnés para mirarlo | `php tests/manual/render-grupos-actividad.php [dark] > /tmp/gac.html` |

## Lo que falta

1. ✅ Módulo creado y nombres comprobados (16/10/2026).
2. Probarlo en producción con un evento de pruebas: que el campo plano del grupo o su enlace llegan en `get_entry_list`
   (si no llegara ninguno, los miembros no sabrían su grupo: §3.1 de `PASAR-LISTA-ESTADO.md`).
3. **Eventos nacionales**: como la lista del autobús, no salen (son de ECE). Misma decisión pendiente (plan 043).
4. Más adelante: «tu patrulla / tu habitación» para participantes y familias en la ficha del evento (Eventos), filtrando
   `activo` y la persona.
