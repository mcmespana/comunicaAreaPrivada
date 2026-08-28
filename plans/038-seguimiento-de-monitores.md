# 038 — Seguimiento de monitores: cómo van de asistencia

**Prioridad: P2.** Esfuerzo: M. Depende de: 033 y del 072 (paginación) — sin
datos completos cualquier porcentaje miente. Encaja con
[`PASAR-LISTA-COORDINACION.md`](../docs/comunica/PASAR-LISTA-COORDINACION.md) §7
y con [`PASAR-LISTA-SEGUIMIENTOS.md`](../docs/comunica/PASAR-LISTA-SEGUIMIENTOS.md),
que es otra cosa (las notas de acompañamiento, no la asistencia).

> Pedido por el propietario el 27/08/2026: «diseñar el seguimiento de los
> monitores, primero de asistencia a las sesiones y reuniones, porcentajes o que
> se muestren cuadraditos, quizá se pueden separar reuniones y sesiones para ver
> cómo van y si tienen las listas pasadas o no las tienen».

---

## 1. Qué pregunta contesta esta pantalla

Coordinación mira a sus monitores **tres veces al año** y siempre con las mismas
tres preguntas. La pantalla tiene que contestarlas de un vistazo, sin abrir
nada:

1. **¿Viene los sábados?** Asistencia a las sesiones semanales.
2. **¿Viene a las reuniones de programación?** Son tres o cuatro al año, así que
   faltar a una pesa mucho más que faltar a un sábado. **Por eso van separadas:
   promediarlas juntas esconde justo lo que se quiere ver.**
3. **¿Pasa su lista?** Un monitor que viene pero no registra deja al grupo sin
   datos, y eso hoy no lo ve nadie.

Las tres son de coordinación. Un monitor no ve el seguimiento de otro
(`PASAR-LISTA-COORDINACION.md` §2).

## 2. La forma: cuadraditos, y el porcentaje detrás

**Cuadraditos, no un porcentaje solo.** Un «78 %» no distingue a quien faltó
cuatro sábados seguidos en enero —y sigue sin venir— de quien falta uno de cada
cinco desde octubre. La fila de cuadrados enseña **el patrón**, que es lo que
hace que coordinación llame o no llame:

```
Marta Besnard            ██▪██ ██░██ ██▪██ ███··          88 %
C2.3 · 2º ESO            Reuniones  ██░·                  2 de 4
                         Listas     ██░██ █████ ██░       11 de 13
```

- **Un cuadrado por sesión celebrada**, en orden. Verde vino, ámbar parcial o
  justificada, rojo no vino, hueco gris sin marcar.
- Los **huecos grises no cuentan en el porcentaje**: una sesión sin pasar no es
  una falta de nadie, y contarla como tal acusa a alguien por un fallo de datos.
  Es la misma regla que ya usa la racha de ausencias.
- El **porcentaje va detrás**, en pequeño, para ordenar y comparar. Nunca solo.
- **Tres filas por monitor**: sesiones, reuniones y listas pasadas. La de listas
  es la que no existe hoy en ninguna parte.

Ojo con el ancho: 24 sesiones de curso a 8 px por cuadrado son 240 px, que caben
en 390 px con márgenes. Si un curso tuviera más, los cuadrados se agrupan por
mes en vez de encoger — un cuadrado de 4 px no se ve ni se toca.

## 3. Los datos: de dónde salen y qué cuestan

Todo lo necesario **ya está en el CRM** y casi todo se lee con cargadores que ya
existen. Nada de campos nuevos.

| Fila | De dónde | Coste |
|---|---|---|
| Sesiones | `stic_Attendances` del evento semanal, por inscripción | Ya se piden por rango de fechas (`sticpa_pl_attendances_for_sessions()`) |
| Reuniones | Las mismas asistencias, pero del evento de reuniones | Una consulta más, misma forma |
| Listas pasadas | `LIS_listas` con `lis_listas_contacts` = el monitor | `sticpa_pl_listas_index()` ya trae todas las de la delegación; **hay que añadir el enlace al monitor al índice** |

⚠️ **La de «listas pasadas» tiene una trampa.** `lis_listas_contacts` apunta al
monitor que pasó la lista (verificado, ver ESTADO §7), pero **una lista de grupo
la puede pasar cualquiera que cubra ese sábado**. Así que «Marta no pasó la
lista del C2.3» puede significar «no vino y la pasó otro», que es correcto. La
fila de listas se lee junto a la de sesiones o no se lee: sin el contexto,
señala a quien no debe. Escribirlo en la pantalla, no solo aquí.

**Coste total estimado**: 2 consultas más que la pantalla de monitores de hoy
(asistencias del evento de reuniones + el enlace de monitor en las listas), las
dos cacheables como `state` y las dos metibles en la tanda paralela con
`sticpa_pl_prime()`. **Ninguna por monitor**: si aparece un 1+N, el diseño está
mal y hay que rehacerlo, no aceptarlo (ver la lección de `monitors_of`).

## 4. Dónde vive

**Dos sitios, un solo cálculo:**

1. **En la ficha del monitor** (`single_stic_pasar_lista_monitor`), completa:
   las tres filas del curso entero. Es la pantalla de la conversación de
   seguimiento.
2. **En la lista de monitores**, una versión mínima: solo el porcentaje de
   sesiones y un aviso cuando algo está mal (dos reuniones seguidas sin venir,
   o menos del 60 % de sesiones). En la lista lo que se busca es **a quién hay
   que mirar**, no el detalle de los treinta.

Los umbrales, filtrables (`sticpa_pl_seguimiento_umbrales`) y con un valor por
defecto conservador: es un dato sensible entre compañeros y un aviso de más
quema la confianza en el aviso.

## 5. Lo que NO hay que hacer

- **No promediar sesiones y reuniones en un número.** Es la pregunta que quiere
  contestar coordinación y se perdería.
- **No pintar un porcentaje sin los cuadrados.** Sin patrón, un número no dice
  si hay que llamar.
- **No enseñarlo a un monitor de otro.** Coordinación, y punto.
- **No inventar campos**: todo sale de asistencias y listas que ya existen.
- **No contar los huecos como faltas.** Un dato que falta no es una ausencia.

## 6. Antes de empezar

1. Confirmar con el propietario los **umbrales** de aviso y si la fila de listas
   pasadas se enseña a todos los coordinadores o solo a la oficina técnica.
2. Comprobar en el CRM cuántas **reuniones** hay de verdad en un curso (en el
   piloto solo hay dos, y de prueba: «Test reu» y «sdadsadasd»). Con dos
   reuniones, un porcentaje es ruido: mejor «2 de 4» y los cuadrados.
3. Medir con `?pl_diag=1` el coste de la pantalla de monitores **después** de
   esta pantalla: el tope de `CosteLlamadasTest` es 11 y esto suma.

## Estado

| Fecha | Qué | Quién |
|---|---|---|
| 2026-08-27 | Diseño escrito a petición del propietario. Sin implementar | revisión |
