/**
 * TAREA · Dejar la caché del área privada calentita
 * ---------------------------------------------------------------------------
 * Va LA ÚLTIMA a propósito: cuando corre, el Guardián ya ha escrito los
 * recuentos y los monitores en los grupos, así que lo que se cachea es el estado
 * bueno y no el de antes de la pasada.
 *
 * ── Qué hace ──────────────────────────────────────────────────────────────
 * Una petición firmada al área privada
 * (`POST /wp-json/comunica/v1/pasar-lista/calentar`) diciendo qué delegaciones
 * calentar. El trabajo lo hace WordPress, que es quien tiene la caché: desde
 * aquí no se puede escribir en sus transients. Ver
 * `inc/stic-pasar-lista-warm.php`.
 *
 * ── Por qué no se calienta desde aquí con los datos que ya tenemos ─────────
 * Porque la caché del área privada no guarda "los grupos": guarda el resultado
 * de SUS cargadores, con su forma, su clave (que lleva delegación, curso y un
 * número de generación) y su TTL. Reproducir todo eso desde Node sería
 * mantener el mismo formato en dos idiomas, y el día que cambie uno se rompe en
 * silencio. Lo que se manda es la ORDEN; los datos los vuelve a pedir quien
 * sabe guardarlos. Sí, eso repite las llamadas al CRM — a las dos de la mañana,
 * que es cuando salen gratis.
 *
 * ── Si no está configurado ────────────────────────────────────────────────
 * Sin `AREA_PRIVADA_URL` y `AREA_PRIVADA_CALENTAR_SECRET` la tarea se salta y lo
 * DICE. No es un fallo: es opcional. Lo que sería un fallo es callárselo y que
 * alguien crea que la caché se está calentando cada noche.
 */

import { createHmac } from 'node:crypto';
import { fetchConReintentos } from '../crm.mjs';
import { delegacionesDe } from '../logica.mjs';

export const clave = 'calentar-cache';
export const titulo = 'Caché del área privada';

const RUTA = '/wp-json/comunica/v1/pasar-lista/calentar';

export async function ejecutar(ctx) {
  const { log, secoDePrueba } = ctx;

  const base = (process.env.AREA_PRIVADA_URL ?? '').trim().replace(/\/+$/, '');
  const secreto = (process.env.AREA_PRIVADA_CALENTAR_SECRET ?? '').trim();
  if (!base || !secreto) {
    return {
      resumen: 'sin configurar, no se ha calentado nada',
      detalles: [
        'Falta ' + (!base ? 'AREA_PRIVADA_URL' : 'AREA_PRIVADA_CALENTAR_SECRET')
        + ' en los secretos del repo. El área privada necesita además'
        + ' STICPA_PL_WARM_SECRET en wp-config.php con el MISMO valor.',
      ],
      problemas: [],
    };
  }

  // Las delegaciones salen de los grupos, que ya están cacheados para toda la
  // pasada: esta tarea no cuesta ni una llamada más al CRM.
  const delegaciones = delegacionesDe(await ctx.grupos());
  if (delegaciones.length === 0) {
    return { resumen: 'ningún grupo tiene delegación asignada', detalles: [], problemas: [] };
  }

  if (secoDePrueba) {
    return {
      resumen: `en seco: se habrían calentado ${delegaciones.length}`,
      detalles: [`delegaciones: ${delegaciones.length}`],
      problemas: [],
    };
  }

  // El cuerpo se firma TAL CUAL se manda. Se serializa una vez y se usa la misma
  // cadena para firmar y para enviar: volver a serializar podría cambiar el
  // orden de las claves y la firma no cuadraría.
  const cuerpo = JSON.stringify({ ts: Math.floor(Date.now() / 1000), delegaciones });
  const firma = createHmac('sha256', secreto).update(cuerpo).digest('hex');

  const resp = await fetchConReintentos(`${base}${RUTA}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Comunica-Firma': `sha256=${firma}` },
    body: cuerpo,
  }, 3, log);

  const texto = await resp.text();
  if (!resp.ok) {
    // El 501 es "no lo has configurado en WordPress", que se arregla en dos
    // minutos y merece decirse con esas palabras y no con un código.
    if (resp.status === 501) {
      throw new Error(
        'El área privada no tiene el calentador configurado: falta STICPA_PL_WARM_SECRET'
        + ' en wp-config.php (con el mismo valor que AREA_PRIVADA_CALENTAR_SECRET).',
      );
    }
    throw new Error(`El área privada respondió HTTP ${resp.status}: ${texto.slice(0, 300)}`);
  }

  let datos;
  try {
    datos = JSON.parse(texto);
  } catch {
    throw new Error(`Respuesta no interpretable del área privada: ${texto.slice(0, 200)}`);
  }

  const detalles = [];
  const problemas = [];
  for (const d of datos?.delegaciones ?? []) {
    if (d.error) {
      problemas.push(`la delegación ${corto(d.delegacion)} no se pudo calentar: ${d.error}`);
      continue;
    }
    detalles.push(
      `${corto(d.delegacion)}: ${d.grupos ?? 0} grupos, ${d.relaciones ?? 0} relaciones, `
      + `${d.eventos ?? 0} eventos, ${d.sesiones ?? 0} sesiones, `
      + `${d.inscripciones ?? 0} inscripciones (${d.ms ?? '?'} ms)`,
    );
  }
  // Un tope alcanzado se dice: si no, "he calentado todo" habiendo calentado
  // las primeras treinta.
  if (Number(datos?.omitidas ?? 0) > 0) {
    problemas.push(`${datos.omitidas} delegaciones se quedaron sin calentar por el tope de la petición`);
  }

  const ok = detalles.length;
  return {
    resumen: ok === 1 ? '1 delegación calentada' : `${ok} delegaciones calentadas`,
    etiquetaDetalles: 'delegaciones calentadas',
    detalles,
    problemas,
  };
}

/** Un id de 36 caracteres en un informe no aporta nada: con el principio basta. */
function corto(id) {
  const s = String(id ?? '');
  return s.length > 8 ? `${s.slice(0, 8)}…` : s;
}
