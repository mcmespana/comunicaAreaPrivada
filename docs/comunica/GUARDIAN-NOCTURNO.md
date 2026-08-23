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
| Datos que hacen falta revisar | `revision-datos` | no | Mira y avisa de lo que rompe Pasar Lista: grupos que no se ven, grupos con chavales y sin monitor, grupos sin código |

Está pensado para crecer: la idea es que todo lo que sea «pasar por el CRM de
madrugada» viva aquí en vez de en cinco workflows distintos.

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
ctx.secoDePrueba               // true = mirar sin escribir
ctx.log(texto)                 // traza en el log del job

// devuelve
{ resumen, detalles: [], problemas: [], etiquetaDetalles? }
```

`detalles` son cosas que has hecho; `problemas` son cosas que alguien tiene que
mirar (salen desplegadas en el informe y disparan el correo). Si tu tarea no
escribe nada, pon `etiquetaDetalles` para que el informe no llame «cambios» a lo
que no lo es.

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
exactamente qué cambiaría y no toca un solo registro.

## 7. Lo que encontró el primer día

La tarea de revisión saltó con esto, y **sigue sin arreglar**:

> Los grupos cuyo `cursos_c` no contenga el curso académico no los ve Pasar
> Lista.

`sticpa_pl_groups()` filtra así:

```php
if ($cursos !== '' && strpos($cursos, $course['label']) === false) { continue; }
```

…donde `$course['label']` es `"2025-2026"`. Pero en el CRM `cursos_c` lleva el
**curso escolar** (`"1º ESO"`, `"Adultos"`, `"Bachiller"`), no el año académico:
comprobado, **ningún** grupo de los 105 contiene `"2025-2026"`. Así que el filtro
descarta todos los grupos que tengan ese campo puesto y solo sobreviven los que
lo tienen vacío.

Tampoco hay otro campo con el año académico: `ajmcm_curso_escolar_c` de la
relación también es el curso escolar (`1_eso`). El año sale únicamente de la
**vigencia** (`start_date` / `end_date`) de la relación, que es lo que ya usa
`sticpa_pl_group_people()`.

Conclusión: ese filtro por curso en el grupo no puede funcionar, porque un grupo
no «es» de un año académico — lo son sus miembros. Está pendiente de decidir qué
se hace; mientras, el guardián lo canta cada noche.
