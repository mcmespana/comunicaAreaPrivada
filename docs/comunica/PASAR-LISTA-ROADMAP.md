# Pasar Lista — fases y melones pendientes

El diseño de lo que se construye ahora está en
[`PASAR-LISTA.md`](PASAR-LISTA.md). Los campos del CRM, en
[`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md).

Aquí va el orden de construcción y todo lo que sabemos que llegará pero **no se
hace ahora**, para que no se pierda ni contamine el diseño de la fase 1.

---

## Dónde estamos

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
- [ ] Aclarar cuál es el campo del móvil del participante
- [ ] Poner `code` a los grupos de Castellón que se vayan a usar
- [ ] Asignar grupo a los participantes y monitores del piloto

### Fase 1 — pasar lista

Home con el atajo, árbol etapa → grupo, pantalla de marcado, guardado en lote y
escritura en `LIS_listas`. Con esto ya se puede jubilar el AppSheet en Castellón.

### Fase 2 — la ficha y la familia

Ficha del participante, contacto de familia con llamada y WhatsApp, teléfono
propio del chaval en el COM, pañuelo editable con confirmación, enlace al
detalle de asistencia por sesión.

### Fase 3 — coordinación

Resumen de grupos, recuentos por etapa y segmento, «datos por revisar», buscador
de participantes. Y los **avisos de comportamiento**: el front va ya en la ficha
de la fase 2, pintado en vacío; aquí se crea el módulo `AVI_avisos` que lo llena
(ver `PASAR-LISTA-CAMPOS-CRM.md` §6).

### Fase 4 — sin conexión

Confirmado como importante, no opcional. Se pasa lista en patios y sótanos, y
además la sesión de MCM App se mantiene siempre, así que entrar sin cobertura y
que la pantalla funcione es parte de la experiencia esperada.

### Fase 5 — más delegaciones

Se hará una **skill** para montar el curso de una delegación nueva (eventos,
sesiones desde su calendario, inscripciones). El piloto de Castellón es la
plantilla.

---

## Melones pendientes

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

### 5. Verificar `CAMPOS.md` contra el CRM por MCP

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
