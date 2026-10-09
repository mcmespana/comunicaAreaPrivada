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
| 1. Auditoría | Un auditor por área, read-only, con capturas del render offline | **A MEDIAS** — ver tabla de abajo |
| 2. Implementación | Lotes por grupos de ficheros, en paralelo, cada uno verificado con tests y capturas | PENDIENTE |
| 3. Revisión | Revisión adversarial del diff entero, `composer lint`, PHPUnit, comprobaciones de design.md §9 | PENDIENTE |

### Auditoría, por área

| Área | Fichero | Estado |
|---|---|---|
| Familias y participantes | [`auditoria-familias.md`](auditoria-familias.md) | **HECHA** (14 hallazgos) |
| Velocidad | `auditoria-velocidad.md` | PENDIENTE (el primer intento se cortó por el límite de sesión) |
| Pasar Lista (monitor) | `auditoria-pasar-lista.md` | PENDIENTE (ídem) |
| Coordinación | `auditoria-coordinacion.md` | PENDIENTE (ídem) |
| Carcasa, escritorio, móvil y app | `auditoria-carcasa.md` | PENDIENTE (ídem) |
| Accesibilidad y ley de diseño | `auditoria-a11y.md` | PENDIENTE (ídem) |
| Funcionalidades nuevas | `docs/comunica/PROPUESTAS-FUNCIONALIDADES-2026-10.md` | PENDIENTE (ídem) |

### Lotes de implementación

| Lote | Hallazgos | Estado |
|---|---|---|
| L1 · Familias | FAM-a1, a2, a3, a4, a5, a6, a7, a9, a11, a12 y la parte sin decisión de a13 | PENDIENTE |

(La tabla crece a medida que se cierran las auditorías de las otras áreas.)

---

## Decisiones del propietario

Cada una con la recomendación de quien la ha visto. Hasta que se decida, no se toca.

- **FAM-a8 — Cambiar de hijo sin volver a la portada.** Hoy el selector manda
  SIEMPRE a la portada, por decisión anterior. Propuesta: volver a la misma
  página solo si no es personal (Eventos, ficha de un evento, Calendario).
  Apuntar a dos hermanos a la misma convivencia pasaría de 7 toques a 5.
  *Recomendación: GO.*
- **FAM-a10 — Eventos e Inscripciones se solapan.** Opción A (S): renombrar
  «Eventos» → «Apuntarse» e «Inscripciones» → «Mis inscripciones» y quitar la
  caja azul del 08/10. Opción B (M): una sola sección «Actividades» con «Para
  apuntarte», «Te has apuntado» y «Ya pasadas». *Recomendación: A ahora, B si la
  gente se sigue liando.*
- **FAM-a13 (la parte de los iconos) — La portada tiene 11 elementos con
  degradado.** design.md §3 pide uno por pantalla, dos como mucho. Propuesta:
  los iconos de acceso en tinta suave (fondo `--primary-50`, trazo
  `--primary-color`). Cambia la cara de la portada, por eso se pregunta.
  *Recomendación: GO.* (Lo que no cambia la cara —«Ver calendario» como botón
  secundario y no repetir «abierto» en la agenda— va en el lote L1.)
- **FAM-a14 — «¿A quién apuntas?» en la ficha del evento**, con el estado de
  cada hijo. Es funcionalidad nueva: va a la lista de propuestas.

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
