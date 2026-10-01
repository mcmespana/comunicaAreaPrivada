# 041 — Pagos: un solo flujo, de la inscripción al recibo

> **Qué es esto.** Un análisis de cómo nace, se enseña y se paga el dinero en el
> área privada, escrito el **01/10/2026** después del lío del Foro de Laicos
> (dos personas pagaron con tarjeta y salieron dos compromisos de más, como
> donativo y de otra delegación). Primero los hechos, luego el diagnóstico,
> luego la propuesta y las fases. **Las decisiones que tocan al propietario
> están todas en §9.**
>
> Datos: censo del CRM por MCP del 01/10/2026 (221 compromisos y 223 pagos
> desde el 1/09) y el código de SinergiaCRM (`SinergiaTIC/SinergiaCRM`, rama
> principal, release 2.11.2).

**Prioridad: P1** (hay dinero real y gente pagando ya). **Esfuerzo: M** en el
área, más decisiones y configuración en el CRM.

---

## 0. En seis líneas

1. Para la persona solo hay una pregunta: **«¿qué debo, de qué, y cómo lo
   pago?»**. El área le contesta con dos palabras del CRM, «compromiso» y
   «pago», repartidas en tres pantallas.
2. **Pagar con tarjeta nunca salda lo que debes**: el formulario de pago de
   SinergiaCRM siempre crea un compromiso NUEVO. Por eso salen duplicados.
3. La propuesta: **una inscripción con precio tiene UN compromiso, y su pago
   pendiente es «lo que falta pagar»**. Pagar con tarjeta lo **sustituye**:
   el pago con tarjeta nuevo se ata a la inscripción y el viejo se cierra.
4. En el área, **«Pagos» pasa a ser «lo que debes / lo que se cobrará / lo
   pagado»**, y la palabra «compromiso» desaparece de la vista de las familias.
5. **El dinero de un evento es de quien lo organiza** (el `assigned_user_id`
   del evento), no de la delegación de quien paga.
6. A medio plazo, SinergiaCRM está terminando **pagos dentro de los
   formularios avanzados**: cuando lo active, el formulario del Foro cobrará
   solo y el área solo tendrá que enseñarlo.

---

## 1. Lo que hay hoy

### 1.1 Cómo trabaja SinergiaCRM con el dinero

- Un **compromiso de pago** (`stic_Payment_Commitments`) es la promesa:
  importe, forma de pago, periodicidad, quién paga y, si se quiere, la
  inscripción de la que viene.
- **Al crearse, el propio CRM le genera sus pagos** (`stic_Payments`). Un
  compromiso de pago único (`punctual`) trae **un** pago:
  - domiciliación → `not_remitted` (entrará en la próxima remesa de la
    delegación);
  - cualquier otro medio → `pending`.
  (`modules/stic_Payment_Commitments/Utils.php`, `createPayment()`.)
- Ese pago cambia de estado cuando entra el dinero:
  - **tarjeta**: el TPV contesta y pasa a `paid` o `rejected_gateway`;
  - **domiciliación**: la remesa;
  - **transferencia, Bizum o efectivo**: **la delegación lo marca a mano** al
    ver el dinero.
- **No existe en SinergiaCRM una forma de «pagar con tarjeta un pago que ya
  existe».** El único camino al TPV es el formulario web clásico
  (`stic_Web_Forms_save`, clase `Donation`), que **siempre crea un compromiso
  nuevo** con su pago y luego manda al TPV. Ningún punto de entrada acepta el
  id de un pago o de un compromiso.
- **Lo que viene.** Los Formularios Web Avanzados (`stic_AWF_Forms`) ya traen
  en el código una acción de pago (`PaymentRouterAction`, con Redsys, Stripe,
  PayPal y CECA). Esa acción coge el **pago pendiente del compromiso que crea
  el propio formulario** y lo lleva al TPV, que es justo el modelo bueno. Pero
  hoy está **apagada** (`isActive = false`, `isUserSelectable = false`):
  «preparación para pagos externos», release 2.11.0. Es lo que el propietario
  llama «los formularios de inscripción aún no soportan los pagos».

### 1.2 Quién crea compromisos hoy (censo, 1/09 → 1/10/2026)

| Origen | Cuántos | Forma | Tipo | De quién | ¿Atado a la inscripción? |
|---|---|---|---|---|---|
| Renovación y altas (formularios), por el automatismo del CRM al guardar una inscripción con IBAN del tutor | 216 | domiciliación | `fee` (cuota 20 €) y `services` (convivencia 45/60 €) | MCM Castellón (y 4 de prueba en Vila-real) | sí |
| Formulario avanzado del Foro de Laicos | 3 | **`kind` («Especie»)** | `services` | **ECE MCM** | sí |
| Pago con tarjeta desde el área («Hacer una aportación» o «Pagar X € con tarjeta») | 2 | tarjeta | **`donation`** | MCM Castellón | **no** |
| Inscripción desde el área con Bizum, transferencia, efectivo o domiciliación (`sticpa_registration_ensure_commitment()`) | 0 todavía | el elegido | `services` | la delegación de la persona | sí |

Pagos desde el 1/09: **218 `not_remitted`** (todas las domiciliaciones) y **5
`pending`** (los 3 «Especie» del Foro y los 2 de tarjeta, que **no se
terminaron**: nadie ha pagado nada todavía).

Y una rareza que importa para la pantalla: los **~72 compromisos de las
convivencias están con `active = 0`**, aunque su pago (`not_remitted`, el
16-17/10) está vivo y entrará en la remesa. Los de la cuota están `active = 1`.
En el área, un compromiso inactivo sale como «Sin actividad»: a una familia le
decimos que no hay nada en marcha de un cobro de 60 € que llega en dos
semanas.

### 1.3 Lo que enseña el área

| Pantalla | Qué enseña | Problema |
|---|---|---|
| Menú «Pagos» (`list_stic_payments`) | Los **pagos** (recibos), cobrados o no, todos revueltos y ordenados por fecha | No separa lo que debes de lo que ya pagaste. Un `not_remitted` («No remesado») es jerga |
| «Compromisos de pago» | Fuera del menú (comentado), pero se llega desde la ficha de la inscripción («Ver el compromiso de pago») y desde la de un pago («Mis compromisos de pago») | Concepto del CRM. «Sin actividad» en las convivencias (ver arriba) |
| Ficha de un compromiso | Importe, forma, «Hacer una aportación» | **Ese botón crea una DONACIÓN nueva y no salda nada.** La nota lo avisa con letra pequeña («Tu delegación la asocia a este compromiso») |
| Ficha de una inscripción | El dato «Pago: 110 € · Especie», con enlace al compromiso. «Pagar X € con tarjeta» **solo si no hay compromiso**. Botón «Mis pagos» | Si el compromiso ya existe (el caso del Foro), no hay botón de pagar: la persona salta al compromiso y paga por «Hacer una aportación» |
| Formulario de pago | Con `registrationId` propio: servicio, de la delegación, el precio del EVENTO y una marca para atarlo a la vuelta. Sin él: donación suelta | El importe es el precio del evento, no lo que debes (en el Foro, un niño paga 70 € y no 110). La delegación es la de quien paga, no la de quien organiza |

---

## 2. El caso del Foro, paso a paso (30/09/2026)

1. David y María se inscriben por el **formulario avanzado del Foro**. Por
   cada uno, el CRM crea la inscripción y un compromiso de **110 €
   «Especie»** (`kind`), tipo Servicios, de **ECE MCM**, con su pago
   `pending`. «Especie» hace aquí de «pendiente», a falta de pagos en el
   formulario.
2. El correo de confirmación dice: *«Opción 1: entra en el área privada y
   paga con tarjeta»* y enlaza a **Inscripciones**.
3. En la ficha de la inscripción **no hay botón de pagar** (ya hay compromiso).
   Se ve «Pago: 110 € · Especie» y un enlace al compromiso.
4. En el compromiso, «Hacer una aportación» lleva al formulario de pago
   **suelto**: 110 €, tipo **donativo**, de **MCM Castellón** (la delegación de
   quien paga), **sin atar** a la inscripción.
5. El CRM crea un **segundo compromiso** «Donativo - 110,00» con su pago
   `pending`, y manda al TPV. Ninguno de los dos terminó el pago.

Resultado: cada persona debe ahora 220 € en el CRM, en dos delegaciones, y uno
de los dos compromisos es un donativo. Si hubieran terminado el pago:

- el donativo habría acabado en el modelo 182;
- el «Especie» habría seguido pendiente para siempre;
- ECE no habría visto el cobro, porque estaría en MCM Castellón.

---

## 3. Diagnóstico: los problemas de fondo

| # | Problema | Dónde se nota |
|---|---|---|
| D1 | **Dos conceptos del CRM** para una pregunta de la familia. «Compromiso» no es una palabra suya | Menú, fichas, notas que explican lo que no se entiende |
| D2 | **Pagar con tarjeta no salda lo que se debe**: crea otro compromiso | Duplicados (§2) |
| D3 | **El importe de la tarjeta sale del precio del evento**, no de lo que se debe | Familias con niños, descuentos, plazas de 70 € |
| D4 | **De quién es el dinero**: la tarjeta va a la delegación de quien paga; el formulario avanzado, a la del evento | El Foro: ECE y Castellón para lo mismo |
| D5 | **«Especie» como «pendiente»**. `kind` es una donación en especie, no «aún no ha pagado» | Informes, y el 182 si alguien filtra por tipo |
| D6 | **Los intentos de tarjeta abandonados se quedan** como compromisos `pending` | Ruido en las listas de la delegación y en «Pagos» de la persona |
| D7 | **El CRM guarda el pago con tarjeta como donativo** aunque se le mande `services` (visto el 30/09; el código público lo respeta, así que es algo de esta instancia). Hoy el área lo corrige al atarlo | Cada pago con tarjeta que no se ata se queda como donativo |
| D8 | **Compromisos inactivos con cobro vivo** (convivencias) | «Sin actividad» en la ficha |
| D9 | **El IBAN del familiar se pierde** (`FAM-02`, plan 015) | Misma zona, mismo flujo |

---

## 4. La propuesta: el modelo

Cinco reglas. Todo lo demás sale de ellas.

**R1. Una inscripción con precio tiene UN compromiso vivo**, y **su pago
pendiente es «lo que falta pagar»**. Lo crea quien crea la inscripción:
- el área (`ensure_commitment()`, ya lo hace);
- el formulario avanzado (ya lo hace, con «Especie»);
- el automatismo del CRM (domiciliación).

**R2. Pagar es saldar ESE pago.** Con domiciliación, transferencia, Bizum o
efectivo lo salda quien corresponde (la remesa o la delegación). **Con tarjeta,
por la limitación de SinergiaCRM (§1.1), se SUSTITUYE**:
1. El formulario de tarjeta crea su compromiso nuevo, que lleva en la
   descripción la marca firmada del **pago** que se va a saldar
   (`[pago:<id>:<firma>]`, la misma técnica que la de la inscripción de hoy).
2. A la vuelta, si el pago con tarjeta está `paid`, el área:
   - **lo ata a la misma inscripción** que el viejo;
   - le copia el tipo (`services` o `fee`) y el dueño, y corrige el
     «Donativo» si el CRM lo ha puesto (D7);
   - **cierra el viejo**: el compromiso con fecha de fin hoy y una nota «Pagado
     con tarjeta: <compromiso nuevo>», y su pago pendiente con el estado de
     «anulado» que tenga el CRM (§9, D-3).
3. Si el pago con tarjeta no se termina, no se ata y no se toca nada. El
   intento abandonado se limpia (§9, D-4).

**R3. El importe a pagar es el del pago pendiente**, no el precio del evento.
Si no hay compromiso (inscripciones antiguas del área), el precio del evento,
como ahora.

**R4. El dinero de un evento es de quien organiza el evento.** La inscripción,
el compromiso y sus pagos se asignan al `assigned_user_id` **del evento**:
- en lo local da lo mismo que hoy (el evento es de la delegación);
- en lo nacional (Foro, ECE) deja de partirse entre delegaciones.

Cambia la regla del CLAUDE.md, «todo va asignado a su delegación», por esta
otra: «a la delegación del evento». **Decisión, §9 D-1.**

**R5. «Pendiente de pagar» no es un medio de pago.** El formulario avanzado no
debería usar «Especie» para eso. §9 D-2.

---

## 5. La propuesta: lo que ve la persona

### 5.1 «Pagos» = lo que debes, lo que se cobrará, lo pagado

Una sola pantalla, con tres bloques. Cada fila dice **de qué es** (el nombre
del evento o «Cuota curso 26-27», no el nombre técnico del compromiso), el
importe y lo que toca hacer.

```
Pagos
──────────────────────────────────────────
Pendiente de pagar                         ← solo si hay algo
┌────────────────────────────────────────┐
│ LC | Foro de Laicos 2026        110 €  │
│ Antes del 5 de noviembre               │
│ [ Pagar con tarjeta ] [ Transferencia ]│
└────────────────────────────────────────┘
Se cobrará por domiciliación
┌────────────────────────────────────────┐
│ Convivencia Inicial · Buñol      60 €  │
│ El 16 de octubre · cuenta ···0112      │
└────────────────────────────────────────┘
Pagado
│ Cuota curso 26-27      20 €  · 2 oct   │
│ …                                      │
```

- **Pendiente de pagar**:
  - los pagos `pending`, con «Pagar con tarjeta» (R2) y, si el evento lo
    tiene, «Cómo pagar por transferencia» (§9 D-5);
  - **los devueltos o rechazados**, en rojo y con lo mismo.
- **Se cobrará por domiciliación**: los `not_remitted`, con la fecha y la
  cuenta enmascarada. Sin botón: no hay nada que hacer, y decirlo tranquiliza.
- **Pagado**: el historial, como el listado de hoy.

**La palabra «compromiso» desaparece de la vista de las familias.** La ficha de
un compromiso se queda solo para lo que de verdad es una promesa que se repite
(una cuota mensual domiciliada), dentro de «Pagos», y no se enlaza desde la
inscripción. Así también deja de verse el «Sin actividad» de D8.

### 5.2 La inscripción dice cómo está su pago

En la ficha de la inscripción, un bloque **«Pago»** con el mismo estado y el
mismo botón que en «Pagos»:
- pendiente → «Pagar con tarjeta»;
- domiciliado → «Se cobrará el 16/10»;
- pagado → «Pagado el 2/10 con tarjeta».

Sin enlace al compromiso. Es donde llega la gente desde el correo del Foro.

### 5.3 Que se vea sin buscarlo

- En la **portada**, una tarjeta arriba si hay algo pendiente de pagar: «Te
  falta pagar el Foro de Laicos (110 €) · Pagar».
- En **Eventos → «Te has apuntado»**, el chip «Pendiente de pago» en vez de
  «Inscrito» cuando toque.

---

## 6. Los flujos de punta a punta

| Caso | Quién crea el compromiso | Cómo se salda | Qué ve la persona |
|---|---|---|---|
| a. Inscripción en el área con **tarjeta** | Hoy: el formulario de tarjeta. Con R2: igual, atado a la inscripción por la marca | TPV | «Pagado» al volver |
| b. Inscripción en el área con **Bizum / transferencia / efectivo** | El área (`ensure_commitment`), pago `pending` | La delegación lo marca pagado. O la persona cambia de idea y paga con tarjeta (R2) | «Pendiente de pagar», con las instrucciones y el botón de tarjeta |
| c. **Domiciliación** (renovación, altas, área) | El automatismo del CRM o el área, pago `not_remitted` | La remesa | «Se cobrará el …»; si se devuelve, «Pendiente» en rojo con el botón de tarjeta |
| d. **Formulario avanzado del Foro, hoy** (sin pagos) | El formulario, con el medio que se decida en D-2 | Transferencia a ECE (la delegación la marca) o tarjeta desde el área (R2) | El correo lleva a la inscripción; allí, «Pagar con tarjeta» |
| e. **Formulario avanzado con pagos** (cuando SinergiaCRM lo active) | El formulario | Su propio TPV, en el momento | «Pagado» directamente. El área solo enseña |
| f. Inscripciones antiguas del área **sin compromiso** | Nadie | Tarjeta desde la inscripción, como hoy | «Pagar X € con tarjeta» |

---

## 7. Qué hace falta fuera del código

- **En la configuración del formulario avanzado del Foro** (CRM): el medio de
  pago del compromiso (D-2) y su dueño (D-1). Y el compromiso sin nombre de
  «Test Inscripcion out» (el formulario no le pone nombre si…, a revisar).
- **El estado de «anulado» de un pago**: mirar en Studio las claves del
  desplegable de estado de Pagos (no inventarla; la API no la valida) y
  apuntarla en `CAMPOS.md`.
- **A SinergiaTIC, dos preguntas**:
  1. por qué el formulario de pago con tarjeta guarda `donation` aunque se le
     mande `services` (D7);
  2. si hay fecha para activar `PaymentRouterAction` en los formularios
     avanzados, y si contemplan **pagar más tarde un pago pendiente** (un
     enlace de pago). Con eso, R2 deja de ser una sustitución.
- **Limpieza inmediata** (§8, F0).

---

## 8. Fases

| Fase | Qué | Dónde | Esfuerzo |
|---|---|---|---|
| **F0** | Limpiar el Foro:<br>· borrar los 2 compromisos «Donativo» pendientes (`00000fdc…` David, `000002ce…` María) y sus pagos;<br>· decidir qué se hace con los 3 «Especie» (D-2);<br>· avisar a quien haga falta de que no se le ha cobrado | CRM (MCP, con permiso) | XS |
| **F1** | **Pagar un pago pendiente con tarjeta (R2 + R3)**:<br>· botón en la inscripción y en el pago;<br>· importe del pago;<br>· tipo y dueño del original;<br>· marca `[pago:…]`;<br>· a la vuelta, atar, corregir y cerrar el viejo.<br>Fuera «Hacer una aportación» de los compromisos que cuelgan de una inscripción | Área | M |
| **F2** | **«Pagos» en tres bloques** (§5.1):<br>· el bloque «Pago» de la inscripción (§5.2);<br>· la tarjeta en la portada y el chip en Eventos (§5.3);<br>· fuera la palabra «compromiso» | Área | M |
| **F3** | **El dueño es el del evento (R4)**, si se decide (D-1):<br>· inscripciones y compromisos que crea el área;<br>· el formulario de tarjeta | Área | S |
| **F4** | Instrucciones de transferencia/Bizum por evento (D-5) | Studio + área | S |
| **F5** | Cuando SinergiaCRM active los pagos en los formularios avanzados: configurarlos en el Foro y los siguientes; el área solo enseña | CRM | — |

Orden: **F0 ya**, **F1 antes del próximo pago del Foro** (el correo sigue
mandando a pagar con tarjeta), F2 después, F3 junto a F1 si se decide.

---

## 9. Decisiones del propietario

| # | Decisión | Opciones | Recomendación |
|---|---|---|---|
| **D-1** | ¿De quién es el dinero de un evento? | a) del organizador del evento (`assigned_user_id` del evento) · b) de la delegación de quien paga (como hoy en el área) | **a**: en lo local da lo mismo, y en lo nacional deja de partirse |
| **D-2** | ¿Qué medio pone el formulario avanzado del Foro al compromiso, mientras no cobre él? | a) que la persona elija en el formulario («transferencia» / «tarjeta») · b) «transferencia» fijo · c) una clave nueva «pendiente de elegir» en Studio · d) seguir con «Especie» | **a**, y si no se puede, **b**. «Especie» no: es una donación en especie |
| **D-3** | Al pagar con tarjeta, ¿qué se hace con el compromiso y el pago viejos? | a) se cierran: fecha de fin hoy, nota, y el pago con estado «anulado» · b) se borran | **a**: queda el rastro, y no se borra dinero |
| **D-4** | Los intentos de tarjeta abandonados (compromiso nuevo con pago pendiente para siempre) | a) el área los cierra pasadas 24 h si no se cobraron · b) se dejan y los limpia la delegación · c) nada | **a**, con la misma nota |
| **D-5** | Las instrucciones de transferencia/Bizum, ¿de dónde salen? | a) un campo de texto en el evento («Cómo pagar») · b) un ajuste por delegación · c) solo en el correo, como hoy | **a**: el Foro y una convivencia local tienen cuentas distintas |
| **D-6** | ¿Se enseña «Compromisos» en algún sitio? | a) no, solo dentro de «Pagos» para lo recurrente · b) como hoy | **a** |

**STOP conditions** para quien implemente F1-F3:
- No escribir una clave de desplegable que no esté en `CAMPOS.md` (el estado
  «anulado», el medio de D-2).
- No borrar pagos ni compromisos con dinero cobrado.
- No tocar los compromisos de domiciliación del automatismo del CRM (las 216
  de la renovación).
