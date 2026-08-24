# Guardián Nocturno del CRM

Cada noche a la **1:30 de Madrid** pasa por SinergiaCRM, hace el mantenimiento
que el área privada no puede permitirse en caliente, y **canta si algo va mal**.

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

**El día se decide en Madrid, no en UTC.** A la 1:30 de un viernes de verano, en
UTC son las 23:30 del **jueves**: con el día del runner, la pasada completa del
viernes caería en jueves media parte del año. Es el mismo motivo por el que la
hora se mira en `Europe/Madrid` (§4), y está cubierto con tests en las dos
estaciones.

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

Los tres primeros ya existen (los usa el workflow de cumpleaños). **El único que
hay que crear es `GUARDIAN_AVISOS_TO`**; sin él todo funciona igual, pero el
aviso se queda en el resumen del job y en el correo de GitHub.

## 4. La hora, y por qué son dos crons

El cron de GitHub es **siempre UTC** y España cambia de hora dos veces al año:

- verano (CEST, UTC+2) → la 01:30 de Madrid son las **23:30 UTC** del día antes
- invierno (CET, UTC+1) → la 01:30 de Madrid son las **00:30 UTC**

Se lanza a las dos horas y el propio script mira la hora real en `Europe/Madrid`
y se sale sin hacer nada si no es la 1:30. Así siempre pasa a la 1:30 de aquí sin
tener que tocar nada en marzo y en octubre. Es el mismo truco que
`cumples-monitores.yml`, a propósito: dos workflows con dos soluciones distintas
para el mismo problema es una de las dos mal.

Los crons de Actions **no son puntuales** (en horas de carga se retrasan). El
script acepta hasta 90 minutos de margen, así que un retraso no se salta la
noche. En ejecución manual la guarda se ignora: si has pulsado el botón, lo
quieres ahora.

## 5. Añadir una tarea

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

## 6. Probarlo sin tocar el CRM

Los tests cubren toda la lógica —vigencia, recuento, el diff, la guarda de la
hora, el informe— sin red:

```bash
node --test .github/scripts/guardian/guardian.test.mjs
```

Y contra la instancia de verdad, sin escribir nada:

*Actions → Guardián Nocturno → Run workflow*, marcando **dry_run**. Dice
exactamente qué cambiaría y no toca un solo registro. Ahí también se elige el
**modo** (por defecto la completa, que es la segura): lanzarlo con `soft` y
`dry_run` es la forma de comprobar que el atajo del suave funciona contra el CRM
real sin arriesgar nada.
