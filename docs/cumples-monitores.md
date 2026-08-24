# Felicitación automática de cumpleaños de monitores

Cada mañana a las **7:00 (hora de Madrid)** un workflow de GitHub Actions mira en
SinergiaCRM qué monitores cumplen años hoy. Si hay alguno manda **dos correos**
con Resend:

1. **A quien cumple**, a su `email1` del CRM: una felicitación de parte de la
   delegación. No lleva sus datos de contacto ni enlaces al CRM — eso es
   información interna que en una felicitación queda raro, y así tampoco le
   mandamos sus propios datos personales por correo sin necesidad.
2. **A la delegación** (`Castellon@movimientoconsolacion.com`): el aviso de quién
   cumple, con botones para felicitar por WhatsApp, por correo o abrir su ficha.

Se manda primero la felicitación y después el aviso: si algo falla a mitad, lo
que no queremos perder es precisamente el mensaje a quien cumple.

Los días que no cumple nadie **no se envía nada** (ni un correo diciendo que no
hay cumpleaños).

Ahora mismo está activo solo para **MCM Castellón**. Si alguien no tiene correo
en el CRM, se le salta la felicitación directa (queda anotado en el log) y el
aviso a la delegación sale igual.

## Qué hay que hacer una sola vez (secretos del repositorio)

En GitHub: **Settings → Secrets and variables → Actions → New repository secret**.

| Secreto | Valor | ¿Obligatorio? |
|---|---|---|
| `CRM_URL` | `https://movimientoconsolacion.sinergiacrm.org` | sí |
| `CRM_CLIENT_ID` | id del cliente OAuth2 del API V8 del CRM | sí |
| `CRM_CLIENT_SECRET` | su secreto | sí |
| `RESEND_API_KEY` | la API key de Resend (la misma que usa Comunica) | sí |
| `CUMPLES_FROM` | remitente, p.ej. `Cumples MCM <comunica@movimientoconsolacion.com>` | no — si falta se usa ese mismo por defecto |

El cliente OAuth2 del CRM se saca en SinergiaCRM: **Admin → OAuth2 Clients and
Tokens → OAuth2 Clients**, creando uno de tipo *Client Credentials*. Con permiso
de lectura sobre **Personas** y **Relaciones con Personas** es suficiente.

El dominio `movimientoconsolacion.com` ya está verificado en Resend (ver
[docs/email-entregabilidad.md](email-entregabilidad.md)), así que SPF y DKIM
pasan sin tocar el DNS.

## El FLAG de delegaciones

Está en [`.github/scripts/cumples/delegaciones.json`](../.github/scripts/cumples/delegaciones.json).
Las 18 delegaciones ya están listadas; para sumar una solo hay que ponerle
`"activa": true` y su correo:

```json
{
  "clave": "onda",
  "activa": true,
  "nombre": "MCM Onda",
  "usuario_crm": "MCM Onda",
  "destinatarios": ["onda@movimientoconsolacion.com"],
  "felicitar_a_la_persona": true
}
```

- `clave` — el valor del campo `ajmcm_delegacion_c` en Relaciones con Personas.
- `usuario_crm` — el `assigned_user_name` del CRM.
- Vale con que cuadre **uno de los dos**. Se hace así porque en el CRM conviven
  claves con erratas históricas (`madird`) y variantes (`vilareal` frente a
  `MCM Vila-real`): con un solo criterio se quedaría gente fuera en silencio.
- Sin `destinatarios` la delegación se salta, aunque esté activa.
- `felicitar_a_la_persona` — a `false` deja de escribir a los monitores y solo
  avisa a la delegación. Por defecto está a `true`.
- `remite` (opcional) permite un remitente distinto por delegación.

## Cómo se decide quién cumple

1. **Relaciones con Personas** (`stic_Contacts_Relationships`), filtrando
   `relationship_type = monitor` y `active = 1`. Son ~165 registros en todo el
   CRM, así que se traen de una vez y se reparten por delegación aquí.
   No se filtra por delegación en la API porque `assigned_user_name` es un campo
   *relate* y la consulta revienta con error de base de datos.
2. **Personas** (`Contacts`), filtrando `birthdate LIKE '%-MM-DD'`. Devuelve
   pocas filas: solo quien cumple hoy, de cualquier delegación.
3. Se cruzan las dos listas por el id de la persona. Se deduplica (en el CRM hay
   gente con la relación de monitor/a repetida por curso) y se queda la
   `start_date` más antigua, que es la que se muestra como "monitor/a desde".

## Ejecutarlo a mano

**Actions → Cumpleaños de monitores → Run workflow.** Campos disponibles:

- `delegaciones` — claves separadas por comas, o `todas` para ignorar el flag.
- `fecha` — simular otro día (`2026-08-18`), útil para ver el correo de verdad.
- `destinatario` — **prueba**: manda *todo* (la felicitación y el aviso) a ese
  buzón, sin escribir ni a la delegación ni a los monitores. El cron nunca usa
  esto. Es la forma segura de ver los correos de verdad en tu bandeja.
- `dry_run` — consulta el CRM pero no envía.
- `demo` — correo de muestra con datos inventados, sin tocar el CRM.

En una ejecución manual el candado de la hora se ignora: si le das al botón a
las 16:30, sale a las 16:30.

Los correos generados se suben como artefacto (`correo-cumples`) en cada
ejecución, así se pueden abrir tal cual salieron sin buscarlos en la bandeja:
`vista-previa-castellon.html` es el aviso a la delegación y
`vista-previa-castellon-persona.html` la felicitación tal y como la ve quien
cumple.

### En local

No hace falta instalar nada (Node 20+, sin dependencias):

```bash
node --test .github/scripts/cumples/
```

```bash
node .github/scripts/cumples/enviar-cumples.mjs --demo
```

Eso deja un `vista-previa-castellon.html` al lado del script (está en
`.gitignore`). Con las variables `CRM_*` puestas se puede hacer una consulta de
verdad sin enviar:

```bash
node .github/scripts/cumples/enviar-cumples.mjs --dry-run --fecha=2026-08-18
```

## Los textos (cambiarlos sin tocar código)

Todo lo que se lee sale de [`mensajes.json`](../.github/scripts/cumples/mensajes.json),
en cuatro listas. El script coge una al azar de cada una:

| Lista | Dónde sale |
|---|---|
| `titulares` | el titular grande del correo a quien cumple |
| `asuntos` | el asunto de ese mismo correo |
| `agradecimientos` | el párrafo de gracias |
| `frases` | la cita del recuadro amarillo (en los dos correos) |

En `titulares` y `asuntos`, `{nombre}` se sustituye por el nombre de pila. Para
añadir frases basta con escribirlas en la lista; hay un test que comprueba que
ninguna se queda vacía y que los huecos `{nombre}` están donde toca.

**La elección es aleatoria pero determinista.** La semilla es la fecha más el id
de la persona, y de ahí sale un hash (FNV-1a, no `Math.random()`). Eso da tres
propiedades que interesan:

- dos monitores que cumplen el mismo día reciben textos distintos;
- si el workflow se ejecuta dos veces hoy, sale exactamente el mismo correo (no
  hay dos versiones del mismo mensaje rondando);
- el año que viene, a la misma persona le toca otro.

Con las listas actuales (8 titulares × 6 asuntos × 14 agradecimientos ×
22 frases) salen unas 14.000 combinaciones, así que no hace falta ampliarlas
para que no se repita.

Los GIFs funcionan igual, con su propia semilla, así que el bicho también cambia.

## Los colores

Son tres constantes al principio de
[`felicitacion.mjs`](../.github/scripts/cumples/felicitacion.mjs), copiadas de
las variables de marca de `css/custom-style.css`:

```js
export const AZUL    = '#1c6fb3';  // --primary-color   (azul Comunica)
export const VIOLETA = '#6c4b9e';  // --accent-color    (cabecera del aviso)
export const MAGENTA = '#9d1e74';  // --secondary-color (cabecera de la felicitación)
```

Cambiando esas tres líneas cambia todo el correo. El resto de tonos (el gris del
fondo, el amarillo del recuadro de la frase, el verde del botón de WhatsApp) van
escritos en el sitio donde se usan.

Y van **en línea, atributo por atributo**, no en una hoja de estilos. No es
dejadez: Gmail y Outlook tiran buena parte del CSS de `<style>`, así que en
correo lo único que se ve igual en todas partes son los `style="..."` de cada
etiqueta y los atributos viejos tipo `bgcolor`. Lo único que va en `<style>` es
la media query del móvil, y está puesta de forma que si el cliente la ignora se
queda la versión de escritorio, que también encaja.

## Los GIFs

Están en `.github/assets/cumples/` (24 ficheros, ~5,4 MB) y se sirven por
`raw.githubusercontent.com`. Se descargaron a propósito en vez de enlazarlos
desde Giphy: un enlace externo que caduque deja el correo roto para siempre, y
esto se envía a diario durante años. Esa carpeta está excluida del despliegue
FTP, así que no pesa en producción.

El script elige uno para la cabecera del aviso, uno por persona en ese aviso y
otro distinto para la felicitación de cada uno, sin repetir dentro del mismo
correo. La elección es aleatoria pero **determinista** (semilla = fecha +
delegación): dos ejecuciones del mismo día dan el mismo correo, y dos días
seguidos salen bichos distintos.

Para añadir o quitar: mete/saca el `.gif` de la carpeta y actualiza
[`gifs.json`](../.github/scripts/cumples/gifs.json) con su `archivo`, `ancho`,
`alto` y una descripción en `alt` (se usa como texto alternativo del correo).
Para comprobar que ninguno se ha caído:

```bash
node .github/scripts/cumples/enviar-cumples.mjs --verificar-gifs
```

## La hora: un solo cron y sin candado

Un cron, a las **06:00 UTC**. En Madrid eso son las **07:00 en invierno y las
08:00 en verano**, porque el cron de GitHub es siempre UTC y España cambia de
hora dos veces al año. Esa hora que baila **se acepta a propósito**: lo que
importa es que salga todos los días, no clavar el minuto.

### Por qué se quitó el candado de la hora

Antes había **dos** crons (05:00 y 06:00 UTC) y el script descartaba el que no
cayera a las 7 en punto de Madrid, para que la hora fuera la misma todo el año.
Parecía más fino y era una trampa:

Los crons de Actions **llegan tarde**. En este repo se vieron 05:21, 05:23 y
05:27 tres días seguidos, con el cron puesto a las 05:00. Con media hora de
retraso, la ejecución buena caía a las 06:0x UTC (08:0x aquí), la descartaba el
candado — **y el señuelo de las 06:00, retrasado igual, también**. Los dos se
iban sin hacer nada: cero correos ese día, y el job **en verde**. Un cumpleaños
perdido sin que nadie se entere.

Con un solo cron no hay nada que descartar y no hace falta candado: un retraso
solo hace que el correo salga más tarde **ese mismo día**. Un «feliz cumple» a
las 8:20 en vez de a las 7 no le importa a nadie; uno que no llega, sí.

> El **día** sí se sigue tomando en `Europe/Madrid`, no en UTC. Si se tomara en
> UTC, una ejecución de madrugada en invierno miraría todavía la fecha de ayer y
> felicitaría a los de ayer.

## Detalles del correo

- Maquetado con tablas y estilos en línea, que es lo único que se ve igual en
  Gmail, Apple Mail y Outlook. Nada de flex/grid ni `@keyframes` (Gmail se los
  come): la animación la ponen los GIFs, que funcionan en todas partes. Outlook
  de escritorio muestra el primer fotograma, y aun así se ve bien.
- Lleva versión en texto plano, `preheader` para la vista previa de la bandeja y
  `color-scheme: light only` para que el modo oscuro no invierta los colores.
- En el aviso a la delegación, botones por persona: **WhatsApp** con la
  felicitación ya escrita (solo si hay móvil que parezca móvil; un fijo no lo
  pone), **Correo** y **Ficha** en el CRM. En la felicitación a la persona no hay
  botones: es un mensaje, no un panel de gestión.
- Los textos y el GIF se eligen por persona y día (ver «Los textos»), así que dos
  monitores del mismo día no reciben lo mismo.
- Si una fecha de nacimiento es una centinela del CRM (año anterior a 1900) se
  muestra "cumple años" sin edad, en vez de un número absurdo.
