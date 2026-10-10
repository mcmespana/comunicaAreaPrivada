# 042 — Pulido integral del área privada (octubre 2026)

**Prioridad: P1.** Esfuerzo: S-M por lote. Abierto el 09/10/2026.

## La petición del propietario (09/10/2026)

Una pasada de pulido de todo el área, con cinco frentes:

1. **Velocidad.** «Sigue yendo bastante lentito; es el CRM, pero valorar todo lo
   que se pueda optimizar».
2. **Flujos y toques.** Que sea intuitivo, fácil, bien agrupado, que se entienda
   y que no parezca hecho por una IA («AI slop»).
3. **Pasar Lista y coordinación.** Afinados, bonitos, entendibles y fáciles.
4. **Diseño en todos los sitios**: escritorio, móvil y dentro de MCM App; fácil
   para familias y «fino filipino» para monitores y coordinación.
5. **Funcionalidades nuevas**: proponerlas y que el propietario diga GO / NO GO.
   Van en [`../../docs/comunica/PROPUESTAS-FUNCIONALIDADES-2026-10.md`](../../docs/comunica/PROPUESTAS-FUNCIONALIDADES-2026-10.md)
   (se crea al terminar la auditoría). **Nada de esa lista se implementa sin su GO.**

Reglas de la pasada: todo va a la rama de trabajo con commits pequeños; **no se
escribe nada en el CRM** (ni por MCP ni con código que dependa de campos que no
estén en `CAMPOS.md`); lo que necesite un campo nuevo o cambie una decisión ya
tomada va a «Decisiones del propietario».

---

## Estado

| Fase | Qué | Estado |
|---|---|---|
| 1. Auditoría | Un auditor por área, read-only, con capturas del render offline | **HECHA** salvo dos cerradas a medias (carcasa y accesibilidad: se cortaron por el límite de uso; lo escrito está verificado, pero puede faltar algo) |
| 2. Implementación | Lotes en dos carriles que no se pisan, un commit por hallazgo, tests y capturas | **CASI HECHA** — ver «Lotes» |
| 3. Revisión | Revisión adversarial del diff entero, `composer lint`, PHPUnit, comprobaciones de design.md §9 | PENDIENTE |
| 4. Propuestas | Funcionalidades nuevas con casilla GO / NO GO | **HECHA** → [`docs/comunica/PROPUESTAS-FUNCIONALIDADES-2026-10.md`](../../docs/comunica/PROPUESTAS-FUNCIONALIDADES-2026-10.md) (12 propuestas) |

### Auditoría, por área

| Área | Fichero | Hallazgos |
|---|---|---|
| Familias y participantes | [`auditoria-familias.md`](auditoria-familias.md) | FAM-a1…a14 |
| Velocidad | [`auditoria-velocidad.md`](auditoria-velocidad.md) | VEL-1…10, con mapa de viajes por pantalla |
| Pasar Lista (monitor) | [`auditoria-pasar-lista.md`](auditoria-pasar-lista.md) | PL-1…13 |
| Coordinación | [`auditoria-coordinacion.md`](auditoria-coordinacion.md) | COO-1…9 |
| Carcasa, escritorio, móvil y app | [`auditoria-carcasa.md`](auditoria-carcasa.md) | CAR-1…7 (a medias) |
| Accesibilidad y ley de diseño | [`auditoria-a11y.md`](auditoria-a11y.md) | A11Y-1…6 (a medias) |

### Lotes

La máquina de la sesión solo corre dos agentes a la vez, así que los lotes van en
**dos carriles**: cada carril es una rama y sus lotes van uno detrás de otro, de
modo que nunca dos agentes tocan el mismo fichero a la vez. Al terminar, las dos
ramas se fusionan en la rama de trabajo.

| Carril (rama local) | Lote | Hallazgos | Estado |
|---|---|---|---|
| A · `lote-A-pasar-lista-coordinacion` | **L2 · Pasar Lista** | PL-1, 2, 5, 3, 13, 4, 7, 8, 6, 10 (sin el punto 5), 12, 11, 9 (sin su punto 3) + VEL-2 | **HECHO** (09/10) — los 14, un commit cada uno |
| A | **L3 · Coordinación** | COO-1, 2, 7, 4, 3, 9, 6, 5 | **HECHO** salvo COO-5 (escritorio), EN CURSO el 10/10 |
| B · `lote-B-familias-velocidad` | **L1 · Familias** | FAM-a1, a3, a6, a2, a7, a5, a4, a9, a12, a11 y la parte sin decisión de a13 | **HECHO** (09/10) — todos, ninguno saltado |
| B | **L4 · Velocidad** | VEL-1, 5, 4, 7, 6 (sin subir la concurrencia), 10 mínima (cabecera `Server-Timing`), (9 y 3 si caben) | VEL-1, 4, 5, 7 **HECHOS**; VEL-6, 10 (y 9/3 si son seguros) EN CURSO el 10/10 |
| A (tras L3) | **L5 · Carcasa y accesibilidad** | CAR-2 (`viewport-fit=cover`), CAR-3, CAR-4, CAR-5 (puntos 1 y 3), CAR-7, A11Y-6, A11Y-2, A11Y-3; y al final A11Y-4 (CSS muerto, solo lo demostrado) y A11Y-5 si es seguro | EN CURSO el 10/10 |

Si una sesión se corta: las ramas de los carriles viven en el contenedor
(`git branch`), con un commit por hallazgo, y los worktrees en el scratchpad de la
sesión. Para seguir, mira `git log claudito/gallant-babbage-n74kag..lote-…` y
continúa por el siguiente hallazgo de la lista.

### Para comprobar en producción después de desplegar

Nada de esto se puede probar sin WordPress ni el CRM de verdad; está apuntado
por quien hizo el cambio.

- **«Pagar con tarjeta»** (VEL-1): el área ya no carga la capa de los formularios
  públicos, cuyo JS arrancaba su motor de alta sobre ese formulario (lleva
  `id="WebToLeadForm"`). Se comprobó que no lo necesita, pero es el punto con más
  riesgo: hacer un pago de prueba.
- **La barra de guardar de Pasar Lista dentro de MCM App** (PL-3), en un iPhone y
  con una lista de 14: que quede por encima de la tab bar, en marcar y en la lista
  de monitores.
- **Subir un documento** (FAM-a9) contra el CRM real (es `SEC-10`).
- **Marcar con `?pl_diag=1`** (VEL-2): si sale
  `ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships`, cachearlo quita dos
  esperas más.
- **Una familia con dos o más hijos que entra por el enlace de un evento**
  (FAM-a1): debe ver «¿A quién quieres apuntar?» y, al elegir, llegar a la ficha
  del evento.

### Pequeñas decisiones que salieron al implementar

- **PL-9, punto 3**: ¿se vacía también la cola de listas sin enviar desde
  cualquier pantalla del área (`js/stic-ui.js`), y no solo desde Pasar Lista?
- **FAM-a11**: el selector de participante de la barra se ve de 31 px, aunque su
  zona táctil ya es de 45 px. Hacerlo de 44 px de verdad hace la barra 13 px más
  alta en todas las pantallas.
- **«Atrás» frente a «Volver»**: el formulario de inscripción ya dice «Volver»;
  documentos, perfil y pagos siguen con «Atrás». Unificarlo es una línea por
  formulario.

---

## Decisiones del propietario

Cada una con la recomendación de quien la ha visto. Hasta que se decida, no se toca.
El detalle está en el fichero de cada auditoría.

**Familias**
- **FAM-a8 — Cambiar de hijo sin volver a la portada.** Hoy el selector manda
  SIEMPRE a la portada (decisión anterior). Propuesta: volver a la misma página si
  no es personal (Eventos, ficha de un evento, Calendario). Apuntar a dos hermanos a
  la misma convivencia pasa de 7 toques a 5. *Recomendación: GO.*
- **FAM-a10 — Eventos e Inscripciones se solapan.** A (S): renombrar «Eventos» →
  «Apuntarse» e «Inscripciones» → «Mis inscripciones» y quitar la caja azul del
  08/10. B (M): una sola sección «Actividades». *Recomendación: A ahora, B si la
  gente se sigue liando.*
- **FAM-a13 (iconos) — 11 elementos con degradado en la portada.** Iconos de acceso
  en tinta suave (fondo `--primary-50`, trazo `--primary-color`). Cambia la cara de
  la portada. *Recomendación: GO.*

**Velocidad**
- **VEL-6 (punto 3) — Subir la concurrencia de las tandas de 4 a 6.** Ahorra una
  espera (~350 ms) en cada tanda de 5-6, p. ej. la portada en frío. El plan 034 dejó
  4 por prudencia con el CRM. Se vuelve atrás con un filtro. *Recomendación: GO
  hablándolo con el proveedor y mirando el `error_log` la semana siguiente.*
- **VEL-8 — Precargar solo con ratón.** En el móvil la precarga al apoyar el dedo
  apenas gana y, si el dedo empieza un scroll, el toque bueno espera ~0,9 s.
  *Recomendación: GO* (en escritorio no cambia nada).

**Pasar Lista y coordinación**
- **PL-10 (punto 5) — Un solo término: «chavales» o «participantes»** en las
  pastillas de Pasar Lista y Mis grupos. *Recomendación: «chavales».*
- **COO-8 — El aviso de «a quién hay que mirar» también en Coordinación**, con una
  línea «2 monitores con faltas seguidas» que filtra el directorio. Cuesta una espera
  más al abrir Coordinación. *Recomendación: GO.*

**Carcasa y diseño**
- **CAR-1 — Para el equipo, sus herramientas primero** en el menú, la barra de
  escritorio y la portada (hoy «Pasar lista» queda bajo el pliegue en el móvil y
  «Coordinación» dentro de «Más»). Deshace el orden decidido el 28/09.
  *Recomendación: GO* (las familias no cambian).
- **CAR-5 (punto 2) — «Salir» dentro del menú en el móvil**, no a 6 px del botón
  «Menú». *Recomendación: GO.*
- **CAR-6 — Cabecera de las fichas en superficie neutra**, sin el segundo bloque de
  degradado pegado a la barra. Cambia la cara de todas las fichas.
  *Recomendación: GO.*
- **A11Y-1 — Anillo de foco visible** (`--focus-ring`, ≥3:1 en los dos temas);
  cambia el «Foco» que fija design.md §4. *Recomendación: GO.*

**Funcionalidades nuevas** (FAM-a14 incluida): en
`docs/comunica/PROPUESTAS-FUNCIONALIDADES-2026-10.md`, con su casilla.

---

## Lo descartado y por qué

(Se rellena con la síntesis de la auditoría.)

---

## Cómo retomar esto

La sesión que abrió este plan tiene un límite de uso de 5 horas y ya se cortó
una vez a mitad de la auditoría. Para seguir **basta con leer este fichero**:

1. **Mira la tabla de «Estado».** Lo que diga PENDIENTE es lo siguiente. Cada
   auditoría hecha está en su fichero de esta carpeta, con problema, evidencia
   (fichero:línea), propuesta concreta, ficheros, tamaño y riesgo.
2. **Para auditar un área pendiente:** read-only, hallazgos verificados contra
   el código y con capturas; formato el de `auditoria-familias.md`. No uses el
   MCP de SinergiaCRM (se come el contexto). Antes de proponer, mira TODO.md
   («Aparcado a propósito») y `plans/README.md` (lo DESCARTADO, p. ej. 028 y
   PERF-08).
3. **Para implementar un lote:** un commit por hallazgo o por grupo pequeño,
   con `composer lint` y `vendor/bin/phpunit` en verde, y las capturas de
   design.md §9 mirándolas de verdad. Al terminar, marca el lote HECHO en la
   tabla y apunta en una línea lo que cambió.
4. **Capturas.** No hay WordPress de pruebas. Arneses de render offline en
   `tests/manual/render-*.php` (`php tests/manual/render-events.php > /tmp/x.html`);
   para pantallas sin arnés, se monta uno igual: `require tests/bootstrap.php`,
   CSS real inline, la función real que pinta. Chromium está en
   `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` y Playwright instalado
   globalmente (`require(\`${execSync('npm root -g')}/playwright\`)`): captura a
   375, 390 y 1280 en los dos temas (`data-stic-scheme=light|dark` en `<html>`),
   y mide `document.documentElement.scrollWidth <= 375` y los pulsables de menos
   de 44 px.
5. **No se mergea ni se despliega** desde esta pasada sin que lo pida el
   propietario. Todo va a la rama de trabajo.
