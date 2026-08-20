/**
 * Cumpleaños de monitores — lógica pura (sin red, sin variables de entorno).
 *
 * Aquí vive todo lo que se puede probar con `node --test`: decidir qué
 * delegaciones toca procesar, cruzar las relaciones del CRM con las personas,
 * calcular la edad y montar el correo. Las llamadas a SinergiaCRM y a Resend
 * están en enviar-cumples.mjs.
 *
 * Va en Node y no en PHP a propósito: el runner de GitHub ya trae Node, no hace
 * falta instalar nada y así se puede ejecutar en local sin montar PHP.
 */

/** Colores de marca del área privada (css/custom-style.css). */
export const AZUL = '#1c6fb3';
export const VIOLETA = '#6c4b9e';
export const MAGENTA = '#9d1e74';

/** CRM, para enlazar la ficha de cada persona. */
export const CRM_WEB = 'https://movimientoconsolacion.sinergiacrm.org';

// ─────────────────────────────────────────────────────────────────────────────
// Fechas y textos
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fecha y hora "de aquí" (Europe/Madrid), pase lo que pase con el reloj UTC
 * del runner. Devuelve { fecha: 'YYYY-MM-DD', hora: 7 }.
 *
 * GitHub solo programa crons en UTC, y España cambia de hora dos veces al año.
 * En vez de pelearse con eso, el workflow lanza a las 05:00 y a las 06:00 UTC y
 * el script se planta aquí: solo sigue si en Madrid son las 7.
 */
export function ahoraEnMadrid(instante = new Date()) {
  const partes = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Madrid',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    hour12: false,
  }).formatToParts(instante);

  const v = (tipo) => partes.find((p) => p.type === tipo).value;
  // Ojo: a medianoche, 'hour' puede venir como "24" en algunas versiones de ICU.
  const hora = Number(v('hour')) % 24;

  return { fecha: `${v('year')}-${v('month')}-${v('day')}`, hora };
}

/** Años que cumple hoy, o null si la fecha del CRM no es utilizable. */
export function edad(nacimiento, hoy) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(nacimiento ?? ''));
  if (!m) return null;
  const anioNac = Number(m[1]);
  const años = Number(String(hoy).slice(0, 4)) - anioNac;
  // Fechas centinela del CRM (1900-01-01 y compañía) mejor no enseñarlas.
  if (anioNac < 1900 || años < 0 || años > 120) return null;
  return años;
}

const MESES = [
  'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];

/** "18 de agosto de 2006". A mano, para no depender de locales del sistema. */
export function fechaLarga(fecha) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(fecha ?? ''));
  if (!m) return String(fecha ?? '');
  return `${Number(m[3])} de ${MESES[Number(m[2]) - 1]} de ${m[1]}`;
}

/** "Ana, Luis y Marta". */
export function listaNatural(items) {
  const xs = [...items];
  if (xs.length === 0) return '';
  if (xs.length === 1) return String(xs[0]);
  const ultimo = xs.pop();
  return `${xs.join(', ')} y ${ultimo}`;
}

/** "monitora" / "monitor" / "monitor/a" según stic_gender_c. */
export function palabraMonitor(genero) {
  const g = String(genero ?? '').trim().toLowerCase();
  if (['female', 'f', 'mujer'].includes(g)) return 'monitora';
  if (['male', 'm', 'hombre'].includes(g)) return 'monitor';
  return 'monitor/a';
}

/**
 * Móvil en formato internacional para el enlace de WhatsApp.
 * Devuelve '' si no parece un móvil usable (y entonces no se pone el botón).
 */
export function movilInternacional(movil) {
  const limpio = String(movil ?? '').replace(/[^0-9]/g, '');
  if (!limpio) return '';
  // 9 dígitos empezando por 6 o 7: móvil español sin prefijo.
  if (/^[67]\d{8}$/.test(limpio)) return `34${limpio}`;
  // Ya viene con prefijo internacional plausible.
  if (/^\d{11,15}$/.test(limpio)) return limpio;
  return '';
}

/**
 * Hash estable y portable (FNV-1a de 32 bits).
 *
 * Se usa para el sorteo de GIFs y para la frase del día: hace falta que la
 * misma entrada dé siempre el mismo resultado, en cualquier versión de Node,
 * para que dos ejecuciones del mismo día produzcan el mismo correo.
 */
export function hash32(texto) {
  let h = 0x811c9dc5;
  const s = String(texto);
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h >>> 0;
}

const FRASES = [
  'Hoy toca tarta. Es ciencia.',
  'Un año más regalando tiempo a los demás. Se nota.',
  'Que no falte el abrazo (ni el trozo de tarta).',
  'Gracias por estar. Hoy te toca a ti que te cuiden.',
  'Un año más de risas, de furgoneta y de campamento.',
  'Que se te note en la cara todo el día.',
  'Hoy el chapuzón de la piscina va dedicado.',
  'Que cumplas muchos más rodeado de esta panda.',
  'Se abre oficialmente el turno de felicitaciones en el grupo.',
  'Un aplauso, que hoy hay motivo.',
  'Avisad a quien tenga que traer la tarta.',
  'Hoy se permite cantar desafinando. Es tradición.',
];

/** Frase alegre del día. Determinista: misma fecha, misma frase. */
export function fraseDelDia(hoy) {
  return FRASES[hash32(hoy) % FRASES.length];
}

// ─────────────────────────────────────────────────────────────────────────────
// Delegaciones y cruce de datos del CRM
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Delegaciones que toca procesar en esta ejecución.
 *
 * El FLAG normal es el campo "activa" de delegaciones.json. `override` permite
 * saltárselo en una ejecución manual: una lista de claves separadas por comas,
 * o "todas" para lanzar todas las que tengan destinatarios.
 */
export function delegacionesActivas(config, override = null) {
  const todas = Array.isArray(config?.delegaciones) ? config.delegaciones : [];
  const texto = String(override ?? '').trim();
  const quiereTodas = texto.toLowerCase() === 'todas';
  const pedidas = texto && !quiereTodas
    ? texto.toLowerCase().split(',').map((s) => s.trim()).filter(Boolean)
    : null;

  return todas.filter((d) => {
    if (!d?.clave) return false;
    if (pedidas) {
      // Ejecución manual con lista explícita: manda la lista, no el flag.
      if (!pedidas.includes(String(d.clave).toLowerCase())) return false;
    } else if (!quiereTodas && !d.activa) {
      return false;
    }
    // Sin destinatarios no hay correo que mandar: mejor saltarla que fallar.
    return Array.isArray(d.destinatarios) && d.destinatarios.length > 0;
  });
}

/** Quita tildes y no-alfanuméricos, para comparar nombres a prueba de erratas. */
export function normaliza(texto) {
  return String(texto ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '') // escapes explícitos: los diacríticos
    // combinantes son invisibles y cualquier editor los puede estropear.
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '');
}

/**
 * ¿Esta relación pertenece a la delegación indicada?
 *
 * Vale con que cuadre el campo propio de la relación (ajmcm_delegacion_c) o el
 * usuario asignado. Se aceptan los dos porque en el CRM conviven claves con
 * erratas históricas ("madird") y variantes ("vilareal" / "MCM Vila-real"), y
 * con un solo criterio se quedaría gente fuera sin que nos enterásemos.
 */
export function relacionEsDe(relacion, delegacion) {
  const clave = normaliza(relacion?.ajmcm_delegacion_c);
  if (clave && clave === normaliza(delegacion.clave)) return true;

  const usuario = normaliza(relacion?.assigned_user_name);
  const esperado = normaliza(delegacion.usuario_crm);
  return Boolean(usuario && esperado && usuario === esperado);
}

/**
 * Índice "id de persona" -> { desde } con sus relaciones de monitor/a activas.
 *
 * Una misma persona puede tener varias relaciones de monitor/a (en el CRM hay
 * altas repetidas por curso), así que se deduplica y se guarda la start_date
 * más antigua, que es la que responde a "monitor/a desde...".
 */
export function indiceMonitores(relaciones, delegacion) {
  const indice = new Map();

  for (const r of relaciones ?? []) {
    if (r?.relationship_type !== 'monitor') continue;
    // La API devuelve el booleano como "1"/"0"; hay que cubrir ambas formas.
    const activa = r.active === true || r.active === 1 || r.active === '1';
    if (!activa) continue;
    if (!relacionEsDe(r, delegacion)) continue;

    const id = String(r.stic_contacts_relationships_contactscontacts_ida ?? '');
    if (!id) continue;

    const desde = r.start_date ? String(r.start_date) : null;
    const previo = indice.get(id);
    if (!previo) {
      indice.set(id, { desde });
    } else if (desde && (!previo.desde || desde < previo.desde)) {
      previo.desde = desde;
    }
  }

  return indice;
}

/**
 * Cruza los contactos que cumplen hoy con el índice de monitores.
 *
 * Se vuelve a comprobar aquí que el día y el mes cuadran: el filtro del CRM va
 * por LIKE '%-MM-DD' y no queremos fiarnos de que la API lo interprete igual
 * siempre. Devuelve la lista ordenada por nombre.
 */
export function cumpleanosDeHoy(contactos, indice, hoy) {
  const mesDia = String(hoy).slice(5); // 'MM-DD'
  const porId = new Map();

  for (const c of contactos ?? []) {
    const id = String(c?.id ?? '');
    if (!id || !indice.has(id) || porId.has(id)) continue;

    const nacimiento = String(c.birthdate ?? '').trim();
    if (!nacimiento || nacimiento.slice(5) !== mesDia) continue;

    const nombre = [c.first_name, c.last_name]
      .map((s) => String(s ?? '').trim())
      .filter(Boolean)
      .join(' ') || 'Sin nombre';

    porId.set(id, {
      id,
      nombre,
      nombreCorto: String(c.first_name ?? '').trim() || nombre,
      nacimiento,
      edad: edad(nacimiento, hoy),
      genero: String(c.stic_gender_c ?? ''),
      movil: String(c.phone_mobile ?? ''),
      email: String(c.email1 ?? ''),
      etapa: String(c.ajmcm_etapa_c ?? ''),
      grupo: String(c.ajmcm_grupotemp_c ?? ''),
      monitorDesde: indice.get(id).desde,
    });
  }

  return [...porId.values()].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'));
}

// ─────────────────────────────────────────────────────────────────────────────
// GIFs
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Reparte GIFs de la bolsa sin repetir dentro del mismo correo.
 *
 * "Al azar" pero determinista: la semilla es la fecha (más la delegación), así
 * que si el workflow se ejecuta dos veces el mismo día sale el mismo correo, y
 * dos delegaciones distintas no reciben el mismo bicho.
 */
export function repartoGifs(gifs, semilla, cuantos) {
  const bolsa = Array.isArray(gifs) ? gifs : [];
  if (bolsa.length === 0 || cuantos < 1) return [];

  // Baraja reproducible: se ordenan los índices por un hash de la semilla.
  const orden = bolsa
    .map((_, i) => i)
    .sort((a, b) => hash32(`${semilla}:${a}`) - hash32(`${semilla}:${b}`) || a - b);

  return Array.from({ length: cuantos }, (_, i) => bolsa[orden[i % bolsa.length]]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Correo
// ─────────────────────────────────────────────────────────────────────────────

/** Escapa para HTML. */
export function esc(texto) {
  return String(texto ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** URL de la ficha de la persona en el CRM. */
export function urlFicha(id) {
  return `${CRM_WEB}/index.php?module=Contacts&action=DetailView&record=${encodeURIComponent(id)}`;
}

/** Asunto del correo. */
export function asunto(cumples, nombreDelegacion) {
  if (cumples.length === 1) {
    const c = cumples[0];
    return `🎂 Hoy es el cumple de ${c.nombre}${c.edad !== null ? ` (${c.edad})` : ''}`
      + ` — ${nombreDelegacion}`;
  }
  const nombres = listaNatural(cumples.map((c) => c.nombreCorto));
  return `🎂 Hoy cumplen años ${cumples.length}: ${nombres} — ${nombreDelegacion}`;
}

/** Versión en texto plano (clientes sin HTML, y ayuda con el antispam). */
export function textoPlano(cumples, delegacion, hoy) {
  const lineas = [`¡Hoy hay cumpleaños en ${delegacion.nombre}!`, ''];

  for (const c of cumples) {
    const palabra = palabraMonitor(c.genero);
    lineas.push(`• ${c.nombre} ${c.edad !== null ? `cumple ${c.edad} años` : 'cumple años'} (${palabra})`);
    lineas.push(`  Nació el ${fechaLarga(c.nacimiento)}.`);
    if (c.monitorDesde) {
      lineas.push(`  ${palabra[0].toUpperCase()}${palabra.slice(1)} desde ${c.monitorDesde.slice(0, 4)}.`);
    }
    if (c.movil) lineas.push(`  Móvil: ${c.movil}`);
    if (c.email) lineas.push(`  Correo: ${c.email}`);
    lineas.push(`  Ficha: ${urlFicha(c.id)}`);
    lineas.push('');
  }

  lineas.push(fraseDelDia(hoy), '', 'Aviso automático del área privada — datos de SinergiaCRM.');
  return lineas.join('\n');
}

/**
 * <img> de un GIF de la bolsa, con el alto recalculado a partir del ancho real.
 * Así el correo no "salta" al cargar y Outlook reserva bien el hueco.
 */
function imgGif(gif, ancho, extra = '') {
  const w = Number(gif.ancho) > 0 ? Number(gif.ancho) : ancho;
  const h = Number(gif.alto) > 0 ? Number(gif.alto) : ancho;
  const alto = Math.round(h * (ancho / w));

  return `<img src="${esc(gif.url)}" width="${ancho}" height="${alto}"`
    + ` alt="${esc(gif.alt ?? 'GIF de cumpleaños')}"`
    + ` style="display:block;border:0;outline:none;text-decoration:none;`
    + `width:${ancho}px;height:${alto}px;${extra}" />`;
}

/** Tarjeta de una persona: su GIF, nombre, edad y botones para felicitar. */
function tarjeta(c, gif) {
  const edadTxt = c.edad !== null
    ? `cumple <strong style="color:${MAGENTA};">${c.edad} años</strong>`
    : 'cumple años';

  const palabra = palabraMonitor(c.genero);
  const chips = [
    c.monitorDesde ? `${palabra[0].toUpperCase()}${palabra.slice(1)} desde ${c.monitorDesde.slice(0, 4)}` : null,
    c.etapa ? `Etapa ${c.etapa}` : null,
    c.grupo ? `Grupo ${c.grupo}` : null,
  ].filter(Boolean);

  const chipsHtml = chips.map((chip) => (
    `<span style="display:inline-block;background-color:#eef2ff;color:#3730a3;`
    + `font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:16px;`
    + `padding:5px 11px;border-radius:999px;margin:0 6px 6px 0;white-space:nowrap;">`
    + `${esc(chip)}</span> `
  )).join('');

  // Botones. El de WhatsApp lleva la felicitación ya escrita.
  const botones = [];
  const wa = movilInternacional(c.movil);
  if (wa) {
    const saludo = `¡Feliz cumpleaños, ${c.nombreCorto}! 🎉🎂 Que lo disfrutes mucho.`;
    botones.push({
      texto: '💬 Felicitar por WhatsApp',
      url: `https://wa.me/${wa}?text=${encodeURIComponent(saludo)}`,
      fondo: '#10b981',
    });
  }
  if (c.email) {
    botones.push({
      texto: '✉️ Correo',
      url: `mailto:${c.email}?subject=${encodeURIComponent(`¡Feliz cumpleaños, ${c.nombreCorto}!`)}`,
      fondo: AZUL,
    });
  }
  botones.push({ texto: '👤 Ficha', url: urlFicha(c.id), fondo: '#6b7280' });

  const botonesHtml = botones.map((b) => (
    `<a href="${esc(b.url)}" style="display:inline-block;background-color:${b.fondo};`
    + `color:#ffffff;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:18px;`
    + `font-weight:bold;text-decoration:none;padding:11px 18px;border-radius:10px;`
    + `margin:0 8px 8px 0;">${b.texto}</a> `
  )).join('');

  return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
    + ' style="background-color:#ffffff;border:1px solid #e9edf5;border-radius:16px;"><tr>'
    // Columna del GIF. En móvil pasa a ocupar toda la fila (ver .cp-col).
    + (gif
      ? '<td class="cp-col" width="196" valign="top" align="center"'
        + ' style="width:196px;padding:16px 4px 8px 16px;line-height:0;">'
        + imgGif(gif, 180, 'border-radius:12px;margin:0 auto;')
        + '</td>'
      : '')
    + `<td class="cp-col" valign="top" style="padding:16px 18px 14px ${gif ? '6px' : '18px'};">`
    + `<div class="cp-nombre" style="font-family:Georgia,'Times New Roman',serif;font-size:26px;`
    + `line-height:32px;font-weight:700;color:${AZUL};padding-bottom:4px;">${esc(c.nombre)}</div>`
    + '<div style="font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:23px;'
    + `color:#374151;padding-bottom:10px;">Hoy ${edadTxt} 🎉<br />`
    + `<span style="color:#6b7280;font-size:13px;">Nació el ${esc(fechaLarga(c.nacimiento))}</span></div>`
    + (chipsHtml ? `<div style="padding-bottom:10px;">${chipsHtml}</div>` : '')
    + `<div>${botonesHtml}</div>`
    + '</td></tr></table>';
}

/**
 * Correo en HTML.
 *
 * Maquetado con tablas y estilos en línea a propósito: es lo único que se ve
 * igual en Gmail, Apple Mail y Outlook. Nada de flex/grid ni @keyframes (Gmail
 * se los come) — la gracia la ponen los GIFs, que sí funcionan en todas partes
 * (Outlook de escritorio muestra el primer fotograma y aun así se ve bien).
 */
export function correoHtml(cumples, delegacion, hoy, gifs = []) {
  const n = cumples.length;
  const titular = n === 1 ? '¡Hoy hay cumpleaños!' : `¡Hoy hay ${n} cumpleaños!`;
  const preheader = n === 1
    ? `${cumples[0].nombre} cumple años hoy. ¡A felicitar!`
    : `Hoy cumplen años ${n} monitores de ${delegacion.nombre}.`;

  // Uno para la cabecera y uno por persona, sin repetirse dentro del correo.
  const reparto = repartoGifs(gifs, `${hoy}|${delegacion.clave}`, n + 1);
  const gifCabecera = reparto[0] ?? null;

  const tarjetas = cumples
    .map((c, i) => tarjeta(c, reparto[i + 1] ?? null))
    .join('<div style="height:14px;line-height:14px;">&nbsp;</div>');

  return `<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" `
    + `"https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es"><head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<!-- Sin esto, el modo oscuro de algunos clientes invierte los colores y el
     texto blanco de la cabecera se queda ilegible. -->
<meta name="color-scheme" content="light only" />
<meta name="supported-color-schemes" content="light only" />
<title>${esc(titular)}</title>
<style type="text/css">
/* Único uso de <style>: la media query del móvil. Si el cliente la ignora,
   se queda la versión de escritorio, que también encaja. */
@media only screen and (max-width:620px){
  .cp-wrap{width:100% !important}
  .cp-col{display:block !important;width:100% !important;text-align:center !important}
  .cp-h1{font-size:30px !important;line-height:36px !important}
  .cp-nombre{font-size:24px !important;line-height:30px !important}
  .cp-pad{padding-left:18px !important;padding-right:18px !important}
}
</style>
</head>
<body style="margin:0;padding:0;background-color:#f4f7fc;-webkit-font-smoothing:antialiased;">

<!-- Preheader: el texto de vista previa de la bandeja. Los caracteres
     invisibles del final evitan que Gmail lo rellene con el cuerpo. -->
<div style="display:none;font-size:1px;color:#f4f7fc;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">${esc(preheader)}${'&#8199;&#65279;'.repeat(40)}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f7fc;">
<tr><td align="center" style="padding:24px 12px;">

<table role="presentation" class="cp-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 28px rgba(21,36,71,0.10);">

  <!-- Cabecera: banda de marca, titular y el GIF protagonista -->
  <tr><td bgcolor="${VIOLETA}" align="center" class="cp-pad" style="background-color:${VIOLETA};padding:26px 30px 24px 30px;">
    <div style="font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:16px;letter-spacing:1.6px;text-transform:uppercase;color:#d8c7ea;padding-bottom:6px;">${esc(delegacion.nombre)}</div>
    <div class="cp-h1" style="font-family:Georgia,'Times New Roman',serif;font-size:34px;line-height:40px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">${esc(titular)}</div>
    <div style="font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:20px;color:#efe6f7;padding:6px 0 18px 0;">${esc(fechaLarga(hoy))}</div>
    ${gifCabecera
      ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">'
        + '<tr><td style="background-color:#ffffff;padding:8px;border-radius:16px;line-height:0;">'
        + imgGif(gifCabecera, 240, 'border-radius:10px;')
        + '</td></tr></table>'
      : ''}
  </td></tr>

  <!-- Tarjetas -->
  <tr><td class="cp-pad" style="padding:26px 30px 10px 30px;">${tarjetas}</td></tr>

  <!-- Frase del día -->
  <tr><td class="cp-pad" style="padding:8px 30px 22px 30px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color:#fef3c7;border-radius:14px;">
      <tr><td align="center" style="padding:18px 22px;font-family:Georgia,'Times New Roman',serif;font-size:17px;line-height:25px;color:#78350f;font-style:italic;">&ldquo;${esc(fraseDelDia(hoy))}&rdquo;</td></tr>
    </table>
  </td></tr>

  <!-- Pie -->
  <tr><td bgcolor="#f9fafb" class="cp-pad" align="center" style="background-color:#f9fafb;padding:18px 30px 22px 30px;border-top:1px solid #e5e7eb;">
    <div style="font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:19px;color:#6b7280;">
      Aviso automático del <strong style="color:#374151;">área privada</strong>.
      Salen aquí las personas con relación de <strong style="color:#374151;">monitor/a activa</strong>
      en ${esc(delegacion.nombre)} según SinergiaCRM.<br />
      Si alguna fecha de nacimiento está mal, corrígela en el CRM y mañana sale bien.
    </div>
  </td></tr>

</table>

</td></tr></table>
</body></html>`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Correo directo a la persona que cumple
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Mensajes de agradecimiento. Se elige uno por persona y día, así que dos
 * monitores del mismo día no reciben el mismo texto y el año que viene toca
 * otro distinto.
 */
const AGRADECIMIENTOS = [
  'Gracias por todas las horas que regalas: las reuniones, los campamentos, los madrugones y las mil cosas que no se ven.',
  'Gracias por estar ahí para los chavales. Lo que siembras con ellos se queda para siempre.',
  'Hoy nos toca a nosotros cuidarte a ti un poquito. Gracias por todo lo que das al Movimiento.',
  'Gracias por el tiempo, la paciencia y las ganas que le pones. Se nota, y mucho.',
  'Gracias por seguir diciendo sí cada curso. El Movimiento es lo que es también por ti.',
];

/** Asunto del correo que va a la persona. */
export function asuntoPersona(cumple) {
  return `🎉 ¡Feliz cumpleaños, ${cumple.nombreCorto}!`;
}

/** Versión en texto plano del correo a la persona. */
export function textoPersona(cumple, delegacion, hoy) {
  const años = cumple.edad !== null ? ` ${cumple.edad}` : '';
  return [
    `¡Feliz cumpleaños, ${cumple.nombreCorto}!`,
    '',
    `Hoy cumples${años} y desde ${delegacion.nombre} queríamos ser de los primeros en decírtelo.`,
    '',
    AGRADECIMIENTOS[hash32(`${hoy}|${cumple.id}`) % AGRADECIMIENTOS.length],
    '',
    fraseDelDia(hoy),
    '',
    `Que lo disfrutes muchísimo. Un abrazo enorme,`,
    delegacion.nombre,
  ].join('\n');
}

/**
 * Correo para la persona que cumple.
 *
 * A propósito NO lleva ni sus datos de contacto ni enlaces al CRM: eso es
 * información interna que tiene sentido en el aviso a la delegación, pero que
 * en una felicitación quedaría raro (y de paso evita mandarle sus propios
 * datos personales por correo sin necesidad).
 */
export function correoPersonaHtml(cumple, delegacion, hoy, gifs = []) {
  const años = cumple.edad !== null
    ? `Hoy cumples <strong style="color:${MAGENTA};">${cumple.edad} años</strong>`
    : 'Hoy es tu cumpleaños';
  const gracias = AGRADECIMIENTOS[hash32(`${hoy}|${cumple.id}`) % AGRADECIMIENTOS.length];

  // Semilla distinta de la del aviso a la delegación, para que la persona no
  // reciba exactamente el mismo bicho que ven en Castellón.
  const gif = repartoGifs(gifs, `persona|${hoy}|${cumple.id}`, 1)[0] ?? null;

  return `<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" `
    + `"https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es"><head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="light only" />
<meta name="supported-color-schemes" content="light only" />
<title>¡Feliz cumpleaños, ${esc(cumple.nombreCorto)}!</title>
<style type="text/css">
@media only screen and (max-width:620px){
  .cp-wrap{width:100% !important}
  .cp-h1{font-size:30px !important;line-height:36px !important}
  .cp-pad{padding-left:20px !important;padding-right:20px !important}
}
</style>
</head>
<body style="margin:0;padding:0;background-color:#f4f7fc;-webkit-font-smoothing:antialiased;">

<div style="display:none;font-size:1px;color:#f4f7fc;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">¡Que cumplas muchos más! Con cariño, ${esc(delegacion.nombre)}.${'&#8199;&#65279;'.repeat(40)}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f7fc;">
<tr><td align="center" style="padding:24px 12px;">

<table role="presentation" class="cp-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 28px rgba(21,36,71,0.10);">

  <!-- Cabecera -->
  <tr><td bgcolor="${MAGENTA}" align="center" class="cp-pad" style="background-color:${MAGENTA};padding:30px 30px 26px 30px;">
    <div class="cp-h1" style="font-family:Georgia,'Times New Roman',serif;font-size:36px;line-height:42px;font-weight:700;color:#ffffff;letter-spacing:-0.4px;">¡Feliz cumpleaños,<br />${esc(cumple.nombreCorto)}! 🎉</div>
    ${gif
      ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin-top:20px;">'
        + '<tr><td style="background-color:#ffffff;padding:8px;border-radius:16px;line-height:0;">'
        + imgGif(gif, 260, 'border-radius:10px;')
        + '</td></tr></table>'
      : ''}
  </td></tr>

  <!-- Cuerpo -->
  <tr><td class="cp-pad" align="center" style="padding:30px 34px 6px 34px;">
    <div style="font-family:Helvetica,Arial,sans-serif;font-size:19px;line-height:28px;color:#1f2937;padding-bottom:14px;">${años}. 🎂</div>
    <div style="font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;color:#374151;padding-bottom:16px;">
      Desde <strong>${esc(delegacion.nombre)}</strong> queríamos ser de los primeros en decírtelo.
    </div>
    <div style="font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;color:#374151;">${esc(gracias)}</div>
  </td></tr>

  <!-- Frase del día -->
  <tr><td class="cp-pad" style="padding:22px 34px 24px 34px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fef3c7" style="background-color:#fef3c7;border-radius:14px;">
      <tr><td align="center" style="padding:18px 22px;font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:26px;color:#78350f;font-style:italic;">&ldquo;${esc(fraseDelDia(hoy))}&rdquo;</td></tr>
    </table>
  </td></tr>

  <!-- Firma -->
  <tr><td class="cp-pad" align="center" style="padding:0 34px 28px 34px;">
    <div style="font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:26px;color:${VIOLETA};">
      Que lo disfrutes muchísimo.<br />Un abrazo enorme,<br />
      <strong>${esc(delegacion.nombre)}</strong>
    </div>
  </td></tr>

  <!-- Pie -->
  <tr><td bgcolor="#f9fafb" class="cp-pad" align="center" style="background-color:#f9fafb;padding:16px 30px 20px 30px;border-top:1px solid #e5e7eb;">
    <div style="font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:19px;color:#9ca3af;">
      Te llega este correo porque estás en SinergiaCRM como ${esc(palabraMonitor(cumple.genero))} de ${esc(delegacion.nombre)}.
    </div>
  </td></tr>

</table>

</td></tr></table>
</body></html>`;
}
