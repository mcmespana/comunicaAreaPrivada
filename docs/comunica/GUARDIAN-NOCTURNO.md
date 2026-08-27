# Guardián Nocturno del CRM

Cada noche de madrugada pasa por SinergiaCRM, hace el mantenimiento que el área
privada no puede permitirse en caliente, y **canta si algo va mal**.

- Workflow: [`.github/workflows/guardian-nocturno.yml`](../../.github/workflows/guardian-nocturno.yml)
- Código: [`.github/scripts/guardian/`](../../.github/scripts/guardian/)
- Se puede lanzar **a mano**: pestaña *Actions → Guardián Nocturno del CRM →
  Run workflow*.

---

## 1. Qué hace hoy

| Tarea | Clave | Escribe | Qué hace |
|---|---|---|---|
| Recuentos y monitores de cada grupo | `recuentos-grupos` | sí | Lleva al grupo cuánta gente tiene y quiénes son sus monitores. El por qué, en [`PASAR-LISTA-RECUENTOS.md`](PASAR-LISTA-RECUENTOS.md) |
| Datos que hacen falta revisar | `revision-datos` | no | Mira y avisa de lo que rompe una lista del sábado: grupos con chavales y sin monitor, grupos sin nadie, grupos sin código corto |
| Caché del área privada | `calentar-cache` | no (en el CRM) | Deja la caché de Pasar Lista hecha, para que el primero que entre el sábado no pague las llamadas. Ver §5 |

Está pensado para crecer: la idea es que todo lo que sea «pasar por el CRM de
madrugada» viva aquí en vez de en cinco workflows distintos.

## 1.b Dos modos: suave y completo

| | Completo | Suave |
|---|---|---|
| **Cuándo** | Madrugada del **viernes y del sábado** | Las demás noches |
| **Qué mira** | Los ~105 grupos, uno por uno | Solo los grupos con alguna relación tocada en los últimos días |
| **Llamadas al CRM** | Una por grupo (~105) | Dos o tres |
| **Se entera de un borrado** | **Sí** | No |
| **Se entera de una vigencia que caduca sola** | **Sí** | No |

**Por qué el suave no puede ir solo.** Dos cosas se le escapan, y las dos por
motivos de fondo, no por descuido:

- **Una relación borrada.** El API excluye los borrados de cualquier consulta,
  así que cuando una relación desaparece no hay forma de saber a qué grupo
  pertenecía. Ese grupo se queda con una persona de más hasta la siguiente
  pasada completa. Solo recontando el grupo entero sale el número bueno.
- **Una vigencia que caduca sola.** Una relación con `end_date` del 31 de agosto
  deja de contar el 1 de septiembre **sin que nadie la modifique**, así que su
  `date_modified` no cambia y el suave no la ve pasar.

El completo del viernes y del sábado es la red que recoge las dos cosas, y cae
justo antes del sábado, que es cuando se miran los números.

**El día se decide en Madrid, no en UTC**, y no depende del cron que haya puesto.
Con el cron actual (00:30 UTC) los dos días coinciden, pero si algún día se mueve
la hora a antes de medianoche UTC dejarían de coincidir —a la 1:30 de un viernes
de verano en UTC son las 23:30 del **jueves**— y la pasada completa del viernes
caería en jueves media parte del año. Leerlo en `Europe/Madrid` es correcto
pase lo que pase, y está cubierto con tests en las dos estaciones.

**Si el suave se rompe, degrada a completo.** Una optimización que falla tiene
que caer al camino seguro, no dejar los números sin tocar: si la consulta
filtrada da error, se hace la pasada completa y el informe lo dice.

**La revisión de datos no pierde cobertura en suave.** Los grupos que no se han
recalculado esa noche se revisan con **el número que ya está escrito en el
grupo**, así que los 105 se miran en los dos modos. Sin eso, un informe en suave
diría «nada que revisar» habiendo mirado tres grupos de 105 — peor que no
revisar. De un grupo sin recuento fresco ni guardado no se opina: un hueco se
entiende, un aviso falso hace que se dejen de leer los avisos.

A mano se puede elegir el modo, y **por defecto sale la completa**, que es la
segura.

## 2. Cómo canta si falla

Cuatro capas, de la que no depende de nadie a la más cómoda:

1. **El job sale rojo** y GitHub manda su propio correo al dueño del repo. Este
   aviso funciona sin configurar nada — es el suelo.
2. **El resumen de la ejecución** lleva el informe entero: una tabla con el
   estado de cada tarea, los avisos desplegados y los cambios plegados. Se abre
   la ejecución y se ve qué pasó sin bucear en el log.
3. **Un correo** con el mismo informe, si están puestos los secretos
   `RESEND_API_KEY` y `GUARDIAN_AVISOS_TO`. **Solo cuando hay algo que contar.**
   Un correo diario diciendo «todo bien» se deja de leer en una semana, y
   entonces el que importa se pierde con él. Para recibirlo siempre, marca
   *avisar_siempre* al lanzarlo a mano.
4. **El informe crudo en JSON** como artefacto, 30 días.

Y una regla importante: **una tarea que falla no tumba a las demás.** Se apunta,
se sigue con el resto, y al final el proceso sale con código 1 para que el job
salga rojo. Si un grupo concreto da error, se apunta ese grupo y se siguen los
otros 104.

> ⚠️ **GitHub desactiva los crons de un repo sin actividad durante 60 días** y
> avisa por correo. Si el guardián deja de pasar sin más explicación, mira eso
> antes que nada.

## 3. Los secretos

| Secreto | ¿Hace falta? | Para qué |
|---|---|---|
| `CRM_URL` | **sí** | `https://movimientoconsolacion.sinergiacrm.org` |
| `CRM_CLIENT_ID` | **sí** | Cliente OAuth2 del API V8 |
| `CRM_CLIENT_SECRET` | **sí** | Su secreto |
| `GUARDIAN_AVISOS_TO` | no | A quién avisar. Varios, separados por comas |
| `RESEND_API_KEY` | no | Ya está puesto para los cumpleaños |
| `GUARDIAN_FROM` | no | Por defecto `Guardián MCM <comunica@movimientoconsolacion.com>` |
| `AREA_PRIVADA_URL` | no | La base del área privada, p. ej. `https://comunica.movimientoconsolacion.com`. Sin barra final |
| `AREA_PRIVADA_CALENTAR_SECRET` | no | El secreto compartido con `wp-config.php`. Ver §5 |

Los tres primeros ya existen (los usa el workflow de cumpleaños). **El único que
hay que crear es `GUARDIAN_AVISOS_TO`**; sin él todo funciona igual, pero el
aviso se queda en el resumen del job y en el correo de GitHub.

## 4. La hora: un cron y sin candado

**Un** cron, a las **00:30 UTC**. En Madrid eso es la **01:30 en invierno y las
02:30 en verano**, porque el cron de GitHub es siempre UTC y España cambia de
hora dos veces al año. Esa hora que baila se acepta: de madrugada no hay nadie
esperando, y lo que importa es que pase.

### Por qué se quitó el candado de la hora

Antes había **dos** crons (23:30 y 00:30 UTC) y el script descartaba el que no
cayera cerca de la 1:30, para clavar la hora todo el año. Un candado así solo
puede hacer daño: los crons de Actions **llegan tarde** —en `cumples-monitores`
se vieron retrasos de 21-27 minutos tres días seguidos— y con retraso suficiente
se descartan **las dos** ejecuciones y la noche se salta entera, con el job en
verde. Ahí se perdió el argumento de clavar la hora.

Con un cron no hay nada que descartar y no hace falta candado: un retraso solo
hace que la pasada sea más tarde **esa misma noche**.

> Mismo criterio que `cumples-monitores.yml`, a propósito: dos workflows con dos
> soluciones distintas para el mismo problema es una de las dos mal.

### Un efecto bueno de elegir las 00:30 UTC

En las dos estaciones cae en el **mismo día de calendario** que en Madrid (01:30
y 02:30 de la madrugada). Así el día que ve la lógica de suave/completo es el
mismo que pone el cron. Con las 23:30 UTC no era así: en UTC era jueves y aquí ya
viernes, y había que tenerlo en la cabeza para no equivocarse.

El día se sigue leyendo en `Europe/Madrid` de todas formas — es lo correcto
independientemente del cron que haya, y está cubierto con tests en las dos
estaciones.

## 5. Dejar la caché del área privada calentita

Las pantallas de Pasar Lista son rápidas con la caché caliente y lentas con la
caché fría, y quien la calienta es **siempre el primero que entra**: el monitor
que abre la app el sábado a las cuatro y cuarto. Ese pago no tiene por qué ser
suyo. El Guardián ya está pasando por el CRM de madrugada, así que al terminar
avisa al área privada y esta se la deja hecha.

### Cómo va

La tarea `calentar-cache` va **la última** a propósito: cuando corre, los
recuentos y los monitores ya están escritos en los grupos, así que lo que se
cachea es el estado bueno.

Hace **una** petición firmada:

```
POST https://…/wp-json/comunica/v1/pasar-lista/calentar
X-Comunica-Firma: sha256=<HMAC-SHA256 del cuerpo con el secreto compartido>

{"ts": 1763000000, "delegaciones": ["<id de usuario de la delegación>", …]}
```

El trabajo lo hace WordPress, que es quien tiene la caché — desde un runner de
GitHub no se puede escribir en sus transients. El código está en
[`inc/stic-pasar-lista-warm.php`](../../inc/stic-pasar-lista-warm.php).

**Las delegaciones salen de los grupos**, que la pasada ya tiene cacheados
(`assigned_user_id` de cada grupo), así que esta tarea **no cuesta ni una llamada
más al CRM** desde el Guardián. Sí cuesta las que hace el área privada al
rellenar: a las dos de la mañana salen gratis.

### Qué se calienta

Solo la familia **`struct`**: grupos, relaciones, eventos, sesiones e
inscripciones. Son las caras y las que no cambian de un día para otro.

La familia **`state`** (las listas de cada sesión, las asistencias, las ausencias
seguidas) **no** se calienta: su TTL son cinco minutos porque tiene que reflejar
lo que se acaba de guardar, así que calentarla a las dos de la mañana no sirve de
nada — a las 2:05 ya está fría.

### El TTL, que es la parte que se olvida

El TTL normal de la estructura son **24 horas** (eran 12, y calentada a la 1:30
caducaba a las 13:30 — **antes** de las sesiones del sábado, que son por la
tarde, con lo que el calentado no había servido para nada). Mientras calienta se
sube a **26 horas**
con el filtro `sticpa_pl_ttl_structure`, que cubre hasta la pasada de la noche
siguiente con margen. Al terminar se quita: una página normal sigue escribiendo
con el TTL de siempre.

### Cómo se configura

Hacen falta **tres cosas**, y las tres son opcionales: sin ellas la tarea se
salta y **lo dice en el informe** en vez de callárselo.

1. Un secreto largo al azar, p. ej. `openssl rand -hex 32`.
2. En `wp-config.php` del sitio:
   ```php
   define('STICPA_PL_WARM_SECRET', 'el-secreto-de-32-bytes-en-hex');
   ```
3. En los secretos del repo: `AREA_PRIVADA_CALENTAR_SECRET` con **el mismo
   valor**, y `AREA_PRIVADA_URL` con la base del sitio.

El secreto **no se autogenera**. Uno generado solo y guardado en `wp_options` no
hay forma de leerlo para copiarlo al secreto de GitHub, y acabaríamos sacándolo
por pantalla en algún sitio. Sin la constante, el endpoint contesta **501** y el
informe del Guardián dice exactamente qué falta.

### Y si toco el CRM a mediodía y lo quiero ver YA

El calentado nocturno no es lo único que invalida la caché: **el botón de
refrescar** de la cabecera de Pasar Lista (el icono circular, arriba a la
derecha) hace lo mismo para tu delegación al momento — tira las dos familias y
la pantalla se vuelve a pintar con lo que hay en el CRM ahora.

Está en la portada, en el árbol de grupos y en el resumen. Y si un grupo sale
**sin participantes**, la propia pantalla de marcar ofrece *"Ya lo he arreglado,
vuelve a mirar"*, que es el mismo refresco sin salir de ahí.

Por debajo es `?refrescar=1` en cualquiera de esas URL, así que también se puede
poner a mano en la barra del navegador.

### Por qué está firmado, y no con un token en la URL

- **HMAC del cuerpo**, no del path: así no se puede cambiar la lista de
  delegaciones de una petición capturada. Cambiarla es justo el ataque —
  calentar, y por tanto **invalidar**, la caché de otra delegación.
- **`ts` dentro del cuerpo** y margen de cinco minutos: sin eso, quien capture
  una petición válida la puede repetir para siempre.
- Se compara con `hash_equals()`, que no se puede medir por tiempos.
- Un token en la URL acabaría en los logs de acceso del servidor. Una cabecera,
  no.

## 6. Añadir una tarea

1. Crea `.github/scripts/guardian/tareas/lo-tuyo.mjs` exportando `clave`,
   `titulo` y `async ejecutar(ctx)`.
2. Añádelo a la lista `TAREAS` de `guardian.mjs`.
3. La parte que **piensa** va en `logica.mjs`, como función pura, y con su test
   en `guardian.test.mjs`. La que **llama al CRM**, en tu fichero de tarea.

El contexto que recibes:

```js
ctx.crm                        // listar / relaciones / actualizar / camposDe
ctx.grupos()                   // todos los grupos, cacheado para toda la pasada
ctx.relacionesDe(id, mod, link) // relaciones de un registro, cacheado
ctx.compartir(k, v)            // dejar algo para otra tarea
ctx.compartido(k)              // cogerlo
ctx.hoy                        // el instante de la pasada (uno para todas)
ctx.modo                       // 'soft' (solo lo cambiado) | 'full' (todo)
ctx.ventanaDias                // días atrás que mira el suave
ctx.secoDePrueba               // true = mirar sin escribir
ctx.log(texto)                 // traza en el log del job

// devuelve
{ resumen, detalles: [], problemas: [], etiquetaDetalles? }
```

`detalles` son cosas que has hecho; `problemas` son cosas que alguien tiene que
mirar (salen desplegadas en el informe y disparan el correo). Si tu tarea no
escribe nada, pon `etiquetaDetalles` para que el informe no llame «cambios» a lo
que no lo es.

Si tu tarea puede mirar «solo lo que ha cambiado», respeta `ctx.modo` — y que el
suave **degrade a completo** si su atajo falla, nunca a no hacer nada. Y que el
resumen diga lo que **pasó** y no lo que se intentó: si el suave cayó a
completo, el informe tiene que decir «completo».

### Las tres reglas de la casa

- **Escribe solo lo que cambia.** Reescribir lo que ya está igual llena el
  registro de auditoría del CRM de cambios que no son cambios, y luego no se
  puede ver quién tocó algo de verdad. La comparación se hace con
  `camposQueCambian()`, que además ignora la diferencia entre `'11'` y `11`
  (el CRM devuelve textos).
- **Comprueba que los campos existen antes de escribir.** Si el módulo no los
  tiene, di qué falta y dónde crearlo, en vez de dejar 105 errores iguales del
  CRM y un informe ilegible. Mira cómo lo hace `recuentos-grupos`.
- **Nada de datos personales en el informe.** Esto acaba en un correo y en los
  logs de GitHub. El nombre de pila de un monitor, vale; un teléfono o un dato
  de salud de un menor, nunca.

## 7. Probarlo sin tocar el CRM

Los tests cubren toda la lógica —vigencia, recuento, el diff, qué modo toca cada
día, el informe— sin red:

```bash
node --test .github/scripts/guardian/guardian.test.mjs
```

Y contra la instancia de verdad, sin escribir nada:

*Actions → Guardián Nocturno → Run workflow*, marcando **dry_run**. Dice
exactamente qué cambiaría y no toca un solo registro. Ahí también se elige el
**modo** (por defecto la completa, que es la segura): lanzarlo con `soft` y
`dry_run` es la forma de comprobar que el atajo del suave funciona contra el CRM
real sin arriesgar nada.

En seco el calentado **no se llama**: se dice cuántas delegaciones se habrían
calentado y ahí se queda. Para probar solo el calentado, sin recuentos:

*Run workflow* con `tareas` = `calentar-cache`. También se puede a mano desde una
terminal, que es la forma de ver el error del área privada tal cual:

```bash
BASE=https://comunica.movimientoconsolacion.com
SECRETO=el-mismo-que-en-wp-config
CUERPO="{\"ts\":$(date +%s),\"delegaciones\":[\"<id de la delegación>\"]}"
FIRMA=$(printf '%s' "$CUERPO" | openssl dgst -sha256 -hmac "$SECRETO" -r | cut -d' ' -f1)

curl -sS -X POST "$BASE/wp-json/comunica/v1/pasar-lista/calentar" \
  -H 'Content-Type: application/json' \
  -H "X-Comunica-Firma: sha256=$FIRMA" \
  --data "$CUERPO"
```

Ojo con el cuerpo: se firma **tal cual se manda**. Si lo vuelves a serializar (o
lo tocas a mano después de firmar) la firma no cuadra y sale un 403.
