# 034 — Rendimiento: calentar, paralelizar y MEDIR

**Prioridad: P1** (P0 si el bug 033 se cierra y la lentitud pasa a ser la
queja principal). Esfuerzo: M. Depende de: 033 para medir guardados; el resto
no depende de nada. Relacionado: 027/029/030 (hechos), 028 (parcial),
[`../docs/comunica/DECISION-BBDD-ESPEJO.md`](../docs/comunica/DECISION-BBDD-ESPEJO.md).

## El diagnóstico en una frase

El 1+N ya no está (§4 del parte de estado); lo que queda es que cada pantalla
hace **6–8 llamadas al CRM EN SERIE** (~200–800 ms cada una = 2–6 s de espera
encadenada) y que la caché que lo esconde **se calienta con el primero que
entra** porque el calentado nocturno no está configurado. Se ataca en este
orden: lo gratis (config), lo estructural (paralelizar), lo fino (caché), y
todo con medición antes/después.

> ## Estado al 27/08/2026
>
> - **Fase 0 (medir): hecha.** Cada petición cuenta llamadas y milisegundos; las
>   que pasan de 3 s dejan una línea en el `error_log` con el desglose, y
>   `?pl_diag=1` lo enseña por pantalla. Falta **la foto real de producción**:
>   sin esos números no se empieza la fase 2.
> - **Fase 1 (calentado nocturno): hecha** — los tres secretos están puestos.
> - **Fase 3 (caché): hecha a medias.** TTL de estructura a 24 h y, sobre todo,
>   **los resultados vacíos ya no se cachean 12 horas** (`sticpa_pl_cache_put`,
>   `sticpa_pl_ttl_empty`): era la causa de que un hipo del CRM dejara un grupo
>   «sin participantes» media jornada. El *write-through* tras guardar se
>   DESCARTA a propósito: chocaría con la relectura de verificación del plan 033,
>   que vale más que la llamada que ahorraría.
> - **Fase 2 (`curl_multi`): sin empezar**, y a propósito. Con el calentado
>   nocturno puesto, los arranques en frío son raros; primero los números.
>
> Coste medido por pantalla (modo normal, caché fría): portada 6, árbol 6,
> marcar 8, resumen 7 — los topes de `CosteLlamadasTest` siguen valiendo.
> En el modo degradado (cuando el CRM no devuelve enlaces ni campos planos) la
> portada se va a **una llamada por persona**: es un respaldo, no el camino
> normal, pero si el registro de peticiones lentas lo delata alguna vez, es ahí
> donde hay que mirar.

## Fase 0 — Medir (sin esto no se acepta nada de lo demás)

1. Contador + cronómetro de llamadas al CRM por petición, en
   `SugarRestApiCall::call()`: nº de llamadas, ms por llamada y total.
2. Sacarlo como cabecera `Server-Timing: crm;dur=<ms total>;desc="<n> calls"`
   (visible en las DevTools del móvil/webview sin tocar nada más) y, para
   coordinación o con `?pl_debug=1` (mismo guard que en 033), un pie de página
   con el desglose por llamada (método + módulo + ms).
3. Registrar una línea `error_log` cuando una petición supere un umbral
   (p. ej. 3 s de CRM total), con el desglose: es el radar de regresiones en
   producción.
4. Tomar la foto ANTES: portada, árbol, marcar y resumen — con caché fría y
   caliente — y apuntarla en este plan (tabla al final).

**Objetivo aceptado**: marcar < 1,5 s con caché caliente; < 4 s en frío.
Portada y árbol < 1 s calientes.

## Fase 1 — Lo gratis: configurar el calentado nocturno

Cero código, máximo retorno. Son los 3 secretos del parte §5 /
`GUARDIAN-NOCTURNO.md` §5:

1. `openssl rand -hex 32` → `define('STICPA_PL_WARM_SECRET', '…')` en
   `wp-config.php`.
2. Secreto de repo `AREA_PRIVADA_CALENTAR_SECRET` = el mismo valor.
3. Secreto de repo `AREA_PRIVADA_URL` = la base del sitio sin barra final.

Verificar en el informe del Guardián de la mañana siguiente que la tarea ya no
se salta, y medir la portada a primera hora (debería salir caliente).

**Es tarea del propietario** (secretos): el plan solo puede dejarlo escrito y
comprobar el informe después.

## Fase 2 — Paralelizar las llamadas independientes (`curl_multi`)

El grueso estructural. En cada pantalla hay llamadas sin dependencia entre sí
que hoy van en fila:

- Marcar: `groups` ∥ `etapa_events`; luego `event_sessions` ∥
  `event_registrations` ∥ `all_listas`; `session_attendances` y `streaks`
  necesitan `regMap` (dependientes).
- Portada / árbol / resumen: colecciones análogas, casi todas independientes.

Diseño propuesto (mínimo, sin reescribir consumidores):

1. En `SugarRestApiCall`, un `callMany(array $requests)` con `curl_multi_*`:
   mismo armado de POST que `call()`, mismas opciones, mismo re-login si un
   resultado trae `number == 11` (reintento individual). Concurrencia ≤ 4
   para no castigar la instancia de SinergiaCRM.
2. Una capa fina de «peticiones diferidas» en los cargadores de
   `inc/stic-pasar-lista-crm.php`: cada cargador ya cachea por su cuenta; se
   añade una función por pantalla (p. ej. `sticpa_pl_prime_marcar($objSCP, …)`)
   que, ANTES del render, lanza en un solo `callMany` las consultas que
   estarían frías, y deja los resultados en los mismos transients que los
   cargadores leen después. Los cargadores no cambian de firma ni de lógica
   (respaldos incluidos) — si el prime falló, todo funciona como hoy.
3. `tests/CosteLlamadasTest.php` sigue mandando en el TOTAL de llamadas; se
   añade la aserción de "tandas" si es viable (cuántos viajes de red, no solo
   cuántas llamadas).

Con RTT de 400 ms y 8 llamadas: hoy ~3,2 s; con 2 tandas paralelas ~0,8–1,2 s.

⚠️ Trampas conocidas al hacerlo: el handle de cURL hoy es UNO reutilizado
(keep-alive, §027) — `curl_multi` necesita un handle por petición; conservar el
keep-alive con `CURLOPT_MAXCONNECTS`/pool propio o aceptar el handshake extra
en las tandas (medir). Y el re-login (número 11) debe refrescar `session` en
TODAS las pendientes, no solo en la que falló.

## Fase 3 — Caché más lista

1. **Write-through tras guardar**: hoy `sticpa_pl_save()` hace flush de la
   familia `state` entera, y la siguiente pantalla (la misma marcar
   re-renderizada) paga las ~7 llamadas justo en el momento de máxima
   atención. En su lugar: actualizar los transients de `listas` y de las
   asistencias afectadas con lo que se acaba de escribir, y NO tirar la
   generación. Cuidado con la trampa documentada de los nombres de caché
   (state vs struct, parte §5).
2. **TTL de `struct`**: subir de 12 h a 24 h (grupos y personas cambian a
   ritmo de curso; el botón refrescar y el aviso «Ya lo he arreglado» ya
   cubren la urgencia).
3. **Terminar 028** (soltar el lock de sesión PHP durante el render): sin
   esto, el prefetch de 030 y cualquier petición paralela de la webview se
   ponen en fila detrás de la página que está renderizando.

## Fase 4 — Solo si tras medir sigue lento

El espejo de lectura en `wpdb` de la fase 3 de
[`DECISION-BBDD-ESPEJO.md`](../docs/comunica/DECISION-BBDD-ESPEJO.md), que ya
queda diseñado allí. No empezarlo sin traer los números de la fase 0 que lo
justifiquen.

## STOP conditions

- STOP si `curl_multi` no está disponible en el hosting (comprobar primero).
- STOP si la instancia de SinergiaCRM se resiente con concurrencia 2–4
  (mirar errores/latencias en la fase de prueba): bajar a 2 o abortar la fase 2.
- No añadir ninguna consulta nueva sin pasar por `CosteLlamadasTest`.
- No tocar la semántica de invalidación (generación en `wp_options`) sin
  releer el parte §5: el contador NO puede vivir en un transient.

## Mediciones

| Fecha | Pantalla | Fría | Caliente | Notas |
|---|---|---|---|---|
| _(pendiente fase 0)_ | | | | |
