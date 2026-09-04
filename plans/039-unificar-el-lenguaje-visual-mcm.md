# 039 — Unificar el lenguaje visual de Comunica MCM (área privada + formularios)

> **Executor instructions**: este plan es autocontenido; quien lo ejecute no ha
> visto la auditoría que lo generó. Léelo entero antes de empezar. Cada fila es
> independiente salvo que diga lo contrario: **se pueden hacer de una en una**,
> en sesiones distintas, y cada una se puede dar por cerrada por su cuenta.
> Al terminar una fila, marca su estado aquí y en `plans/README.md`.

## Status

- **Prioridad**: P2 (ninguna fila es un bug; todas son deuda que hace que la
  próxima pantalla cueste más de lo que debería)
- **Esfuerzo**: S por fila, L el conjunto
- **Riesgo**: BAJO en las filas de documentación · MEDIO en las de CSS
  (regresión visual por cascada)
- **Depende de**: nada. Coordina con 018 si tocas la cascada de `stic-base.css`
- **Categoría**: diseño / deuda técnica / DX
- **Origen**: redacción de [`design.md`](../design.md), 2026-08-31
- **Repos afectados**: `comunicaAreaPrivada` y `comunicaFormularios`
  (branch de trabajo: el mismo nombre en los dos)

## Por qué existe este plan

Al escribir `design.md` —la ley de diseño común a las dos superficies— salieron
a la luz sitios donde las dos superficies, o el propio repo, dicen cosas
distintas sobre lo mismo. Ninguno rompe nada hoy. Todos hacen que un agente que
llega nuevo tenga que **elegir**, y cada elección es una divergencia más.

La regla que ordena todo el plan: **un concepto, un nombre, un sitio.**

---

## Tabla de filas

| # | Qué | Repo | Esfuerzo | Riesgo | Estado |
|---|---|---|---|---|---|
| 1 | Un solo vocabulario de tokens entre las dos superficies | ambos | M | MED | **HECHO** (2026-08-31) |
| 2 | Fijar la escala de breakpoints y dejar de inventar | ambos | S | BAJO | **HECHO** (2026-08-31) |
| 3 | Renumerar las secciones de `custom-style.css` y matar la §17 | privada | S | BAJO | **HECHO** (2026-08-31) |
| 4 | Subir `--pl-on-brand` y `--pl-brand-fixed` a tokens globales | privada | S | BAJO | **HECHO** (2026-08-31) |
| 5 | Inter en los formularios: la misma letra en las dos superficies | formularios | S | BAJO | **HECHO** (2026-08-31) |
| 6 | Limpiar las referencias muertas de `docs/design-system.md` | privada | S | NULO | **HECHO** (2026-08-31) |
| 7 | Los dos verdes: separar identidad de estado, por nombre | ambos | S | BAJO | **HECHO** (2026-08-31) — con cambio de criterio |
| 8 | `design.md` de los formularios: anexo, no copia | formularios | S | NULO | **HECHO** (2026-08-31) |
| 9 | `CAMPOS.md`: un original y una copia que se sincroniza sola | ambos | S | BAJO | **HECHO** (2026-08-31) |

---

## Fila 1 — Un solo vocabulario de tokens

**Qué pasa.** Los mismos tres hex de marca tienen dos nombres según el repo:

| Concepto | Área privada | Formularios |
|---|---|---|
| Azul Comunica `#1c6fb3` | `--primary-color` | `--mcm-brand-blue` |
| Violeta `#6c4b9e` | `--accent-color` | `--mcm-brand-violet` |
| Magenta `#9d1e74` | `--secondary-color` | `--mcm-brand-magenta` |
| Tooltip oscuro | `--tip-bg` / `--tip-fg` | `--mcm-tip-bg` / `--mcm-tip-fg` |
| Superficie / alterna | `--surface` / `--surface-2` | `--mcm-surface` / `--mcm-surface-alt` |

Radios, sombras y curvas **sí** comparten nombre y valor (`--radius-*`,
`--shadow-*`, `--ease-*`), lo cual demuestra que se puede. A los formularios les
faltan `--radius-2xl`, `--shadow-xl` y `--shadow-glow`.

**Decisión propuesta: el nombre bueno es el semántico del área privada**
(`--primary-color`, `--surface`…), no el de marca (`--mcm-brand-blue`). Motivo:
el nombre semántico permite recolorear sin renombrar, que es justo lo que
promete el sistema; `--mcm-brand-blue` obliga a que el azul sea azul para
siempre. Además el área privada es la superficie grande y la que más crece.

**Pasos** (no hace falta un big bang; el alias hace el trabajo):

1. En `crm_comunica_estilos.css` §1, **añade los nombres semánticos** apuntando
   a los existentes, sin borrar nada:
   ```css
   --primary-color: var(--mcm-brand-blue);
   --accent-color:  var(--mcm-brand-violet);
   --secondary-color: var(--mcm-brand-magenta);
   --surface: var(--mcm-surface);
   --surface-2: var(--mcm-surface-alt);
   --tip-bg: var(--mcm-tip-bg);
   --tip-fg: var(--mcm-tip-fg);
   ```
   Y las tres piezas de escala que faltan, con el valor exacto del área privada:
   `--radius-2xl: 1.9rem`, `--shadow-xl`, `--shadow-glow`.
2. **A partir de ahí, todo lo nuevo se escribe con el nombre semántico.** Los
   `--mcm-*` quedan como sinónimos vivos, no como error.
3. Migrar las reglas viejas es opcional y se hace **por componente**, verificando
   con captura antes/después. No lo hagas en bloque.
4. Añade la equivalencia a la §4 de `design.md` y quita de allí el aviso ⚠️.

**STOP condition.** Si al añadir los alias alguna regla existente cambia de
aspecto, es que ese nombre ya se usaba con otro valor: párate y dilo, no lo
resuelvas por tu cuenta.

**Verificación.** Un diff del CSS **sin comentarios**: solo pueden aparecer
líneas nuevas, ninguna modificada.

**Resultado (2026-08-31).** Hecho: diecinueve declaraciones nuevas y cero
modificadas. Como ninguna regla las usa todavía, no hay cambio visual posible y
la captura no aporta nada sobre el diff.

**Una trampa que costó dos intentos, y por eso está en el CSS con comentario
largo.** `--shadow-glow` puesto en el `:root` se quedaba azul en los formularios
magenta y verde: el `var()` de dentro de una custom property se resuelve **en el
elemento donde se declara**, así que se congelaba con el acento por defecto del
`:root`. Vive en el bloque `.crm-profile-app` de la §13 —el mismo elemento que
lleva la clase `.theme-*`— y sale de `--form-ring`, que es el token que cada
tema cambia (no `--mcm-accent-ring`, que es solo el valor azul por defecto).
Comprobado en Chromium en los tres temas: azul, magenta y verde.

---

## Fila 2 — Fijar la escala de breakpoints

**Qué pasa.** Contando **solo media queries reales** (el primer recuento coló
`max-width` que eran propiedades, no consultas):

- área privada (`custom-style.css` + `pasar-lista.css`): `340, 420, 480, 560,
  561, 600, 640, 641, 767, 768, 860, 900, 1024` → **trece**
- formularios: `480, 560, 600, 720, 768, 961, 1100` → **siete**
- comunes a las dos: solo `480, 560, 600, 768`

Dos productos que son el mismo producto, con quince anchos distintos entre los
dos. (Corrección sobre la primera redacción de este plan: el `340` **sí**
existe, con un uso.)

**Decisión tomada.** Escala única de cinco, y solo cinco:

| Ancho | Para qué |
|---|---|
| `≤ 340px` | Rescate de móviles estrechos: lo de dos columnas pasa a una |
| `≤ 640px` | **El breakpoint móvil de referencia.** Densidad, tipografía, qué se cae |
| `≤ 767px` | Navegación colapsada y calendario |
| `≥ 768px` | Dos columnas en formularios y listados |
| `≥ 1024px` | Layouts con columna lateral (home, login partido) |

**El rescate va en 340 y no en 360**, rectificando la primera propuesta de este
plan: 360px es el ancho de media Android, y pasar ahí a una columna rompería la
home de seis accesos sin scroll justo en los móviles más comunes. 340 recoge los
de verdad estrechos (320) sin tocar a los demás. Además ya existe en el CSS.

El `≥ 860px` del login partido es el histórico que más se nota: su sitio es
`≥ 1024px`, pero mover eso cambia el layout del login y pide su propia captura,
así que no se hace aquí.

**Pasos.**

1. Escribe la escala en `design.md` §5 (hoy no está: se remite a design-system)
   y en `docs/design-system.md` §11.1, sustituyendo la lista actual.
2. **No migres el CSS existente de golpe.** Marca los anchos huérfanos con un
   comentario `/* histórico — migrar a 640 al tocar este bloque */` y migra
   cuando toques cada bloque por otro motivo.
3. Prohibido un ancho nuevo fuera de la escala sin dejar escrito el porqué al
   lado.

**Verificación.** Captura a 359 / 360 / 375 / 640 / 641 / 768 / 1024 de la home,
un listado y el login. Nada debe saltar.

---

## Fila 3 — Renumerar `custom-style.css` y matar la §17

**Qué pasa.** El fichero tiene **números de sección repetidos**:

- `24. PULIDO — chip destacado, dropzones, botón suave` (línea 2074) y
  `24. OPTIMIZACIÓN PANTALLA LOGIN PARA ORDENADOR (PC)` (línea 2226)
- `27. CAMPOS NO EDITABLES + AVISO DE REFERENCIA` (2562) y
  `27. MODAL DE CONFIRMACIÓN DE BORRADO` (2671)
- `45. CALENDARIO DE ACTIVIDADES` (4424), `45. CALENDARIO EN MÓVIL` (4675) y
  `45. CÓDIGO DE ACCESO (OTP)` (5601)

Y la `17. MODO OSCURO (Desactivado a petición)` **miente**: el modo oscuro
existe y vive en la §44 (plan 016). Alguien que busque «modo oscuro» encuentra
primero la §17 y se cree que no hay.

Esto importa porque `design-system.md` y los comentarios del propio CSS
**referencian secciones por número** («§29 CSS», «§44.a»). Un número duplicado
convierte una referencia en una adivinanza.

**Pasos.**

1. Renumerar **solo las duplicadas**, dándoles el siguiente número libre al
   final (52, 53, 54…), y dejar en su sitio la cabecera con una nota
   `(antes §24b)` para que quien busque por el número viejo lo encuentre.
2. Sustituir el cuerpo de la §17 por una línea:
   `17. MODO OSCURO → ver §44. (Esta sección se vació al implementar el plan 016.)`
3. `grep -rn "§[0-9]" docs/ css/ inc/ *.php` y actualizar las referencias que
   apunten a una de las renumeradas.

**STOP condition.** Renumerar es mover comentarios, **no reglas**. Si te ves
moviendo bloques de CSS de sitio, para: el orden de la cascada es portante
(lo midió el plan 018).

**Verificación.** `git diff` no debe contener ninguna línea que no sea un
comentario.

**Resultado (2026-08-31).** Hecho. Los duplicados posteriores pasaron a §52
(login PC), §53 (modal de borrado, con sus subsecciones `53.a-f`), §54
(calendario en móvil) y §55 (código de acceso), cada uno con `[antes §N,
duplicado]` al lado. La §17 apunta a la §44 y explica por qué se conserva el
rótulo vacío. Referencias cruzadas actualizadas en `docs/design-system.md` y en
los propios comentarios del CSS.

En vez de la captura se hizo algo más fuerte: **quitando los comentarios, el CSS
es idéntico byte a byte** al de antes (1041 llaves de apertura y de cierre en los
dos). Un cambio que no toca ninguna regla no puede cambiar el render.

---

## Fila 4 — Subir `--pl-on-brand` y `--pl-brand-fixed` a tokens globales

**Qué pasa.** `css/pasar-lista.css` define dos tokens con un comentario
excelente que explica un problema **general**, no de Pasar Lista:

- `--pl-on-brand: #fff` — el texto que va encima de un relleno de marca. No
  puede ser `--white`, que en oscuro vale `#16171a` y deja letras negras sobre
  el degradado.
- `--pl-brand-fixed: #1c6fb3` — el azul de marca sin tematizar, para cuando el
  fondo es blanco de verdad (encima de un degradado). `--primary-color` en
  oscuro se aclara a `#3f95d6` y ahí da 3,2:1, por debajo de AA.

El propio comentario dice que **~40 reglas de `custom-style.css` ya hacen eso
mismo a mano**. Es un token global viviendo en el sitio equivocado.

**Pasos.**

1. Crear en `custom-style.css` §1: `--on-brand: #fff` y `--brand-blue-fixed: #1c6fb3`,
   con el comentario de `pasar-lista.css` traído entero (explica el porqué mejor
   que nada que se escriba de nuevo).
2. En `pasar-lista.css`, dejar `--pl-on-brand: var(--on-brand)` y
   `--pl-brand-fixed: var(--brand-blue-fixed)`. No tocar sus usos.
3. Documentar los dos en `design.md` §3 (el bloque de «texto encima de marca»)
   y en `design-system.md` §3.
4. Migrar los `#fff` literales de `custom-style.css` que estén encima de marca
   es **opcional y por componente**. No lo hagas en bloque.

**Verificación.** Comprobado en Chromium que los cuatro tokens resuelven al
mismo valor que antes, en claro **y** en oscuro:
`--on-brand` / `--pl-on-brand` → `#fff`, `--brand-blue-fixed` /
`--pl-brand-fixed` → `#1c6fb3`.

**Resultado (2026-08-31).** Hecho. Los `--pl-*` se quedan como alias porque los
usan ~25 reglas de esa hoja y renombrarlas no aportaba nada. Documentados en
`design.md` §3 y en `design-system.md` §3.

---

## Fila 5 — Inter en los formularios

**Qué pasa.** El área privada **autoaloja** Inter
(`fonts/inter-latin-var.woff2`, con preload) y siempre se ve Inter. Los
formularios declaran `'Inter', system-ui, …` pero **no la sirven**: el
comentario del CSS dice, textualmente, «Inter si el dispositivo la tiene».

Casi ningún móvil tiene Inter instalada. Resultado: el formulario público se ve
en San Francisco o en Roboto y el área privada en Inter. **Las dos superficies
de la misma marca no comparten letra**, que es lo primero que se nota.

**Decisión propuesta: servir Inter también en los formularios**, autoalojada,
con el mismo `.woff2` variable que ya existe en el otro repo. Son ~50 KB, se
cachean y el formulario ya carga una hoja de estilos completa.

**Pasos.**

1. Copiar `fonts/inter-latin-var.woff2` (y `inter-latin-ext-var.woff2`) del repo
   `comunicaAreaPrivada` a la raíz del hosting de formularios, junto al CSS.
2. `@font-face` al principio de `crm_comunica_estilos.css`, con
   `font-display: swap` y `unicode-range` para no descargar el ext si no hace falta.
3. Confirmar que el `deploy-ftp.yml` sube la carpeta de fuentes.
4. Medir el peso añadido y el LCP antes/después en el formulario más pesado.

**Alternativa si pesa demasiado** (decisión del propietario, no del ejecutor):
alinear en la dirección contraria —quitar Inter de los formularios *y*
documentar que la pila del sistema es deliberada ahí—. Es peor para la marca
pero es coherente, que es más de lo que hay hoy.

**STOP condition.** Si el LCP móvil empeora más de 150ms, para y pregunta.

**Resultado (2026-08-31).** Hecho, con los dos subsets. Los woff2 van en
`fonts/` del repo de formularios y el deploy FTP los sube a la **raíz** del
hosting —no a `/formularios/`— porque las URL relativas de un CSS se resuelven
contra el propio CSS, que se despliega en la raíz. El job falla si los woff2 no
están, igual que ya hacía con el CSS.

Sobre el peso: la idea de partir en `latin` (48 KB) y `latin-ext` (85 KB) es que
el navegador pida solo el que necesite. En la prueba con texto español Chromium
se trajo solo `latin`, pero en otra combinación se trajo los dos, así que el
comentario del CSS cuenta con **136 KB en el peor caso** en vez de prometer un
ahorro que no se pudo demostrar en el entorno de prueba. Se cachean.

No se midió el LCP contra producción (no hay acceso desde aquí). Si alguien lo
mide y se ha ido de los 150ms, la salida es servir solo el subset `latin`:
cubre el español entero y son 48 KB.

---

## Fila 6 — Limpiar `docs/design-system.md`

**Qué pasa.** La §3 dice: «Los grises (`--gray-50`…`--gray-900`) vienen de
`stic-modern-style.css` y se usan por variable». Ese fichero **no existe**: lo
borró UI-15 (se consolidó en `stic-base.css`) y el plan 018 fase 1 movió todos
los tokens al `:root` único de `custom-style.css` §1, que es donde están los
grises hoy.

Es exactamente el tipo de frase que hace que un agente vaya a buscar un fichero
fantasma y acabe creando un token nuevo «porque no encontraba el sitio».

**Pasos.**

1. Corregir esa frase: los grises viven en `custom-style.css` §1 como todo lo demás.
2. Barrido: `grep -rn "stic-modern-style\|stic-style.css" docs/ *.md` y arreglar
   lo que quede vivo (dejando las menciones **históricas** de UI-15 marcadas como tales).
3. Añadir al principio de `design-system.md` una línea que diga qué relación
   tiene con `design.md`: **`design.md` = la ley (qué se decide y por qué);
   `design-system.md` = el manual de la casa (dónde está cada cosa en este
   repo)**. Sin esa línea acabaremos con dos fuentes de verdad.

**Resultado (2026-08-31).** Hecho junto con la redacción de `design.md`: la §3
dice ya que los grises viven en el `:root` único, y la cabecera lleva la línea
de relación entre los dos documentos.

---

## Fila 7 — Los dos verdes

**Qué pasa.** Conviven dos verdes que no son el mismo concepto y cuyos nombres
no lo dejan claro:

- `--mcm-brand-green: #0f8a50` (formularios) — **identidad**: el color del
  formulario de participantes, hermano del azul y del magenta.
- `--success-color: #2f9e44` (área privada) / `--mcm-success: #15803d`
  (formularios) — **estado**: «esto ha salido bien».

Tres verdes, dos conceptos, y ninguno de los nombres dice cuál es cuál. Además
`--success-color` y `--mcm-success` son dos hex distintos para el mismo concepto
en las dos superficies.

**Pasos.**

1. Renombrar el de identidad a `--brand-green` en los formularios (alias del
   viejo para no romper), y dejar escrito en `design.md` §3 —ya está— que no se
   usan el uno por el otro.
2. Elegir **un** hex para «éxito» en las dos superficies. Propuesta: el
   `#15803d` de los formularios, que tiene más contraste sobre blanco que el
   `#2f9e44` del área privada (**5,02:1 frente a 3,45:1**; el segundo no llega a
   AA para texto normal). **Matiz importante**: en el área privada
   `--success-color` es el *acento/borde* y el texto de estado sale de
   `--success-dark` (`#076b4d`, 6,52:1), así que ahí no hay un fallo de
   contraste que arreglar — lo que se arregla es tener **un hex por concepto**
   en las dos superficies. Si eso
   cambia demasiadas pantallas del área privada, la alternativa es al revés:
   entonces hay que comprobar el AA de cada uso sobre `--success-soft`.
3. Verificar contraste de los dos verdes sobre sus fondos suaves, en los dos
   temas del área privada.

**STOP condition.** Si el cambio de hex de «éxito» toca más de diez reglas,
sepáralo a su propia sesión con capturas antes/después.

## Resultado de la fila 7 (2026-08-31) — con cambio de criterio

**El paso 2 de arriba estaba mal y NO se ha hecho.** Al mirar el uso real, los
dos verdes de «éxito» no juegan el mismo papel:

| | Se usa como | Contraste | Umbral |
|---|---|---|---|
| formularios `--mcm-success` `#15803d` | **solo** `color:` (4 usos, todos texto) | 4,79:1 sobre su fondo | 4,5 |
| área privada `--success-color` `#2f9e44` | borde / acento | 3,45:1 sobre blanco | 3,0 |
| área privada `--success-dark` `#076b4d` | texto | 6,21:1 | 4,5 |

Cada uno cumple **su** umbral. Igualarlos rompería uno de los dos: el borde no
necesita 4,5 y el texto no se conforma con 3,45. Unificar el hex habría sido un
error, y la propuesta original lo daba por bueno mirando solo el contraste sobre
blanco sin comprobar el rol.

**Lo que sí se ha hecho** es unificar el nombre del ROL, que era el problema de
verdad («ningún nombre decía cuál era cuál»). Los formularios declaran ya
`--brand-green` (identidad), `--success-dark` (texto), `--success-soft` (fondo),
`--success-border`, `--danger-color`, `--warning-dark` y `--warning-soft`.

De paso: **`--mcm-brand-green` no lo usa ninguna regla.** El acento del tema
verde sale de `--mcm-accent-green` (mismo hex) y el degradado lleva el hex fijo
a propósito. Se conserva porque es el nombre del color de marca, y ahora lo dice
su comentario.

---

## Fila 8 — `design.md` de los formularios (HECHO)

Se ha escrito `design.md` en `comunicaFormularios` como **anexo de superficie**,
no como copia: contiene lo que solo aplica allí (tema claro único, un color por
formulario, el panel de acceso, las dos trampas ya pagadas) y remite al canónico
para todo lo demás.

**El motivo de que no sea una copia** es la lección de `CAMPOS.md`, que vive
duplicado en los dos repos y hay que acordarse de sincronizar a mano. Dos copias
de 400 líneas de diseño divergirían en un mes. Si algún día se publica el
canónico en una URL (que es como lo hace Vercel), el anexo pasa a apuntar a esa
URL y desaparece el problema.

---

## Fila 9 — `CAMPOS.md`: un original y una copia que se sincroniza sola (HECHO)

**Qué pasaba.** `CAMPOS.md` —la fuente de la verdad de los campos del CRM— vivía
duplicado en los dos repos, y la regla era «acuérdate de subirlo también al
otro». No funcionó: al comparar las dos copias el 31/08/2026, la del área
privada tenía 88 líneas que la de formularios no, todas añadidas desde la
revisión del 28/08 contra el CRM:

- los tres campos `stic_conduct_code_c`, `stic_confidentiality_agreement_c` y
  `stic_time_availability_c`;
- **`phone_mobile`**, que en la copia vieja seguía listado como «No usar» siendo
  el móvil de verdad de participantes, familiares y monitores;
- `phone_other` y `do_not_call`;
- los dos módulos nuevos (`ajmcm_GRUPOS` y `stic_Contacts_Relationships`), con
  el aviso de que el `default` de `ajmcm_pasar_lista_c` es `1`.

Es el peor sitio posible para divergir: los formularios públicos **escriben** en
esos campos. Un agente trabajando solo en aquel repo habría leído «phone_mobile
— No usar» y lo habría creído.

Ninguna divergencia era un conflicto: el área privada era un superconjunto
estricto. Por eso se pudo unificar sin decidir nada.

**Qué se ha hecho.**

1. **El original es `comunicaAreaPrivada/docs/comunica/CAMPOS.md`.** Lleva en la
   cabecera que es el único y que la otra es una copia generada.
2. La copia de `comunicaFormularios` se ha puesto al día (cuerpo idéntico byte a
   byte) y lleva un banner HTML de «copia generada, no la edites».
3. **`comunicaFormularios/.github/workflows/sync-campos.yml`**: cada día a las
   6:10 UTC (y a mano con `workflow_dispatch`) descarga el original y, si
   difiere, abre un PR con el cambio. El PR recuerda comprobar si algún
   formulario escribe en un campo renombrado o retirado.
4. `AGENTS.md` y `CLAUDE.md` ya no dicen «acuérdate de subirlo a mano».

**Por qué el trabajo lo hace el repo privado.** `comunicaAreaPrivada` es
**público**, así que su `raw.githubusercontent.com` se lee sin credenciales. Si
lo hiciera al revés (el área privada empujando al otro) haría falta un token
cruzado con permiso de escritura sobre un repo privado. Tirando en vez de
empujar, no hace falta ningún secreto.

**Guarda contra el fallo tonto.** Si la descarga falla o devuelve una página de
error, el job comprueba que el fichero no está vacío y que empieza por
`# Campos del CRM`; si no, falla sin tocar nada. Verificado en seco: con la copia
al día no abre PR (es idempotente) y con basura descargada se planta.

**Lo que queda por decidir** (no bloquea): el retardo es de hasta un día. Se
puede bajar a instantáneo con un `repository_dispatch` desde el área privada,
pero eso sí necesita un token cruzado. Con la frecuencia real de cambios de
`CAMPOS.md`, un día parece de sobra.

---

## Estado: CERRADO (2026-08-31)

Las nueve filas están hechas. Lo que queda escrito para el futuro, y que **no**
es deuda sino decisiones tomadas:

- **`design.md` no se publica en una URL.** Se propuso (es como lo hace Vercel:
  una URL a la que cualquier agente puede apuntar) y el propietario dijo que no.
  El repo `comunicaAreaPrivada` es público, así que el `raw` de GitHub ya sirve
  de facto si algún día hace falta. No volver a proponerlo.
- **Los `--mcm-*` y los anchos históricos no se migran en bloque.** Se migran
  cuando se toca ese componente por otro motivo. Un barrido masivo es QA visual
  iterativo y ya se midió en el plan 018 que no es un batch seguro.
- **Los dos verdes de «éxito» no comparten hex, y es correcto** (ver fila 7).
- **El `≥ 860px` del login partido** sigue fuera de la escala. Su sitio es
  `≥ 1024px`, pero moverlo cambia el layout del login y pide su propia captura.
  Es el candidato número uno si alguien retoma esto.

### Post-mortem de la fila 2 (2026-09-01): la escala se erosionó en un día

La primera pantalla escrita DESPUÉS de cerrar el plan —«Mis grupos» y el
sangrado lateral— metió **dos anchos fuera de la escala** sin que nada se
quejara: un `48rem` (que es 768px, pero escrito en otra unidad) y un `26rem`
inventado para una fila de formulario.

No fue mala fe ni descuido raro: quien escribía no tenía el plan delante, y una
escala que solo vive en un documento no se defiende sola. Corregidos a `768px` y
`640px` el 01/09.

**Lo que se hizo para que no vuelva a pasar:** `TokensCssTest` comprueba ahora
que todas las media queries de `pasar-lista.css` estén en la escala, con la
lista de anchos permitidos escrita en el propio test. Un ancho nuevo ya no entra
en silencio: hay que añadirlo ahí, y ese es justo el momento de escribir el
porqué que pide la fila 2.

**Y el mismo cinturón para `custom-style.css`, con trinquete** (01/09/2026).
Ahí la mitad de los anchos están fuera de la escala (15 de 30) y la fila 2 dice
explícitamente que **no** se migran en bloque. La pega de una lista de
excepciones es que nadie la poda, así que son DOS tests que la obligan a
encoger:

1. `test_custom_style_no_admite_anchos_nuevos` — no entra un ancho que no esté
   ni en la escala ni en la lista congelada. O sea: no entran nuevos.
2. `test_la_lista_de_historicos_no_se_pudre` — un histórico que ya no se usa
   hace fallar el test hasta que se borra de la lista. La lista solo vale
   mientras describa la realidad.

Congelados hoy: `900px`, `860px` (el login partido que nombra esta sección),
`641px`, `600px`, `561px`, `560px`, `480px`, `420px`. Cuando migres uno, quítalo
de `anchosHistoricos()` en el mismo commit.

Queda el repo de **formularios**, que este repo no puede vigilar desde sus
tests.
