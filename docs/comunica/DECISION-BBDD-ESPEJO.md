# ¿BBDD espejo (Neon) para Pasar Lista? — análisis y decisión

**Fecha: 27/08/2026.** Pregunta del propietario: ¿montamos una base de datos
espejo en Neon, que el front del área privada hable con ella (rápida, con el
esquema a nuestro gusto), y una sincronización al CRM cada 2–12 horas, con
sync manual para altas puntuales?

**Decisión corta: espejo SÍ como dirección, Neon NO como sitio, y no todavía
como prioridad.** El espejo correcto para este plugin es **la MySQL de
WordPress (`wpdb`)**, que ya está pagada, es local y no añade ni un vendor ni
un RTT. Y antes de construirlo hay dos cosas que dan más por menos: cerrar el
bug de guardado con visibilidad de errores (plan 033) y exprimir lo barato del
rendimiento (plan 034). El espejo de lectura queda diseñado aquí como fase 3,
para ejecutarlo solo si tras medir con caché caliente sigue siendo lento.

---

## 1. Los hechos que pesan

- **El volumen es minúsculo.** Piloto: 1 delegación, ~24 sesiones, decenas de
  personas. A escala completa: ~12 delegaciones × ~20 grupos × ~12 chavales →
  **miles de filas, no millones**. Cualquier cosa cabe en un transient; el
  problema nunca ha sido el tamaño de los datos.
- **La lentitud real es el RTT en serie.** Cada pantalla hace 6–8 llamadas a
  la API SOAP-ish de SuiteCRM, **una detrás de otra**, a ~200–800 ms cada una.
  Eso son 2–6 s de pura espera de red, que la caché (24 h struct / 5 min
  state) esconde solo cuando está caliente. El calentado nocturno ya está
  configurado (27/08/2026), así que los arranques en frío son la excepción.
- **No hay acceso a la BBDD ni al servidor de SinergiaCRM.** Todo pasa por la
  API v4 (la misma que usa el plugin y el MCP). Un webhook desde SuiteCRM es
  inviable sin acceso a ficheros (los workflows de serie no llaman URLs).
  Cualquier sincronización sería **polling por `date_modified`** + disparo
  manual.
- **El sincronizador usaría la MISMA API frágil** que hoy: los enlaces
  anidados que no vienen, los filtros por `_ida` que dan 400, los errores que
  llegan como `{number, name}` en un 200. Un espejo no elimina esos problemas:
  los muda a un cron donde fallan sin pantalla delante. Con el agravante de
  que hoy ni siquiera vemos los errores de escritura (plan 033): construir un
  espejo ENCIMA de un canal que no sabemos leer es apilar pisos sobre el
  sótano inundado.
- **Son datos de menores.** Meter nombres, asistencias y avisos de chavales en
  un tercero nuevo (Neon, EE. UU./UE según región) añade superficie RGPD:
  contrato de encargo, análisis, otro sitio que borrar cuando toque. La MySQL
  de WordPress ya tiene esos datos en tránsito (transients) bajo el mismo
  paraguas que el hosting actual.
- **Neon desde PHP compartido no es gratis técnicamente**: driver Postgres en
  el hosting (pdo_pgsql no siempre está), TLS, otra credencial que rotar, y
  cada consulta vuelve a ser un viaje de red externo — decenas de ms en el
  mejor caso, que es exactamente la enfermedad que queremos curar. `wpdb` es
  un socket local.

## 2. Las opciones, comparadas

| | A. Seguir: caché + arreglos | B. Espejo completo en Neon | C. Espejo de lectura en `wpdb` | D. Journal de escrituras en `wpdb` |
|---|---|---|---|---|
| Latencia de lectura | Buena con caché caliente; mala en frío | Excelente | Excelente, sin red externa | (no aplica: es de escritura) |
| Fiabilidad de guardado | La del CRM, hoy invisible | Escritura local + sync (retry) | La del CRM | **Escritura local SIEMPRE + reintentos visibles** |
| Infra nueva | Ninguna | Vendor + credenciales + driver | Ninguna (tabla(s) en la MySQL de WP) | Ninguna (1 tabla) |
| Superficie de fallo nueva | Ninguna | Sync bidireccional, conflictos, datos rancios ×2 | Sync de lectura (1 dirección) | Reintentos (1 dirección, idempotente) |
| RGPD | Como hoy | Tercero nuevo con datos de menores | Como hoy | Como hoy |
| Esfuerzo | S–M | L–XL | M | S–M |

**B pierde contra C en todo lo que importa aquí**: mismo beneficio de lectura,
sin vendor, sin red externa, sin RGPD nuevo. Neon tendría sentido si el front
dejara de ser PHP/WordPress (una app nativa contra una API propia, otro
hosting) — no es el caso ni el plan.

## 3. La decisión, en fases

### Fase 1 (ya, plan 033) — journal de escrituras + errores visibles

Lo que de verdad quema del guardado no es la velocidad: es que **falla en
silencio**. El plan 033 hace visibles los errores; y el paso natural
inmediatamente después es la opción D: una tabla `wp_sticpa_pl_saves` donde
cada guardado aterriza PRIMERO (instantáneo, nunca se pierde), se intenta
contra el CRM en el momento, y si el CRM falla queda `pendiente` con
reintento (cron cada 5–15 min + al siguiente pageview) y un contador visible
(«2 listas pendientes de subir al CRM»). Eso da el 80 % del beneficio del
espejo (el monitor guarda YA y fiable) con el 5 % del coste. La cola offline
de `localStorage` sigue igual: cubre el «sin cobertura»; el journal cubre el
«el CRM no me responde».

### Fase 2 (ya, plan 034) — calentado nocturno + paralelizar + medir

Configurar el calentado (3 secretos, cero código), paralelizar con
`curl_multi` las llamadas independientes de cada pantalla, y **medir** con
Server-Timing. Con caché caliente + llamadas en paralelo, el objetivo es
marcar < 1,5 s. Si se alcanza, el espejo de lectura no hace falta.

### Fase 3 (solo si la fase 2 se queda corta) — espejo de LECTURA en `wpdb`

Diseño ya cerrado para no repensarlo:

- **Tablas** (por colección, alineadas con los cargadores actuales):
  `pl_groups`, `pl_people` (relaciones persona-grupo vigentes), `pl_events`,
  `pl_sessions`, `pl_registrations`, `pl_listas`, `pl_attendances`. Columnas =
  lo que ya piden los `fields` de los cargadores + `crm_id` + `synced_at`.
  Nombres de campo del CRM según `CAMPOS.md`, como siempre.
- **Quién lo llena**: el Guardián Nocturno (ya existe y ya calienta caché) —
  struct 1×/día; state (listas/asistencias) cada 2 h por
  `date_modified >= última sync`. El botón «refrescar» dispara una sync de la
  delegación en vez de (además de) tirar la caché.
- **Write-through**: cuando el plugin escribe en el CRM (o encola en el
  journal), actualiza el espejo él mismo en la misma petición → lo recién
  guardado se ve al instante sin esperar al polling. Un alta hecha en el CRM
  directamente tarda ≤2 h o un toque de «refrescar».
- **Los cargadores no cambian de firma**: `sticpa_pl_groups()`, `_all_listas()`,
  etc. leen del espejo si existe y está fresco, y del CRM si no (mismo patrón
  respaldo que ya usan). El front no se entera: cero cambio de pantallas.
- **El CRM sigue siendo la fuente de la verdad.** El espejo es un caché con
  forma de tabla; se puede vaciar y reconstruir entero en minutos. Nada de
  conflictos bidireccionales: las escrituras SIEMPRE van al CRM (vía journal),
  nunca «viven» solo en el espejo.

### Qué haría cambiar la decisión

- Acceso de solo lectura a la BBDD real de SinergiaCRM (el propietario podría
  pedirlo): entonces el sync de lectura se haría por SQL directo y el espejo
  sube puestos, aunque seguiría siendo en `wpdb`, no en Neon.
- Una app nativa que quiera API propia sin pasar por WordPress: ahí sí, una
  BBDD gestionada (Neon/Supabase — Supabase ya está en el ecosistema MCM) con
  API encima. Ese día se migra el diseño de la fase 3 casi tal cual.

## 4. Resumen para el propietario

No montes Neon: el dato es pequeño, el enemigo es el RTT en serie y el fallo
silencioso de escritura, y los tres se arreglan más barato dentro de lo que ya
tienes. Orden: 033 (guardado fiable y visible, con journal en `wpdb`) → 034
(calentar + paralelizar + medir) → y solo si sigue lento, espejo de lectura en
`wpdb` con el diseño de arriba, que te da «el front lindísimo» sin vendor
nuevo, sin RGPD nuevo y con sync cada 2 h + refresco manual, que es justo lo
que pedías — solo que en la MySQL que ya pagas.
