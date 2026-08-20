#!/usr/bin/env node
/**
 * Felicitación diaria de cumpleaños de monitores.
 *
 * Qué hace, por orden:
 *   1. Comprueba que en Madrid son las 7 (GitHub solo programa crons en UTC y
 *      España cambia de hora; ver la nota del workflow).
 *   2. Pide un token al API V8 de SinergiaCRM (OAuth2 client_credentials).
 *   3. Baja las relaciones de tipo "monitor" activas de Relaciones con Personas.
 *   4. Baja las personas cuya fecha de nacimiento cae hoy (filtro LIKE '%-MM-DD').
 *   5. Cruza ambas listas por delegación y manda un correo con Resend.
 *
 * Uso:
 *   node enviar-cumples.mjs                        # lo que hace el cron
 *   node enviar-cumples.mjs --demo                 # correo de muestra, sin CRM
 *   node enviar-cumples.mjs --dry-run              # consulta el CRM pero no envía
 *   node enviar-cumples.mjs --fecha=2026-08-18     # simula otro día
 *   node enviar-cumples.mjs --delegaciones=todas   # ignora el flag "activa"
 *   node enviar-cumples.mjs --verificar-gifs       # comprueba que los GIFs cargan
 *   node enviar-cumples.mjs --destinatario=yo@x.org --demo   # prueba a mi correo
 *
 * Variables de entorno (secretos del repositorio):
 *   CRM_URL            https://movimientoconsolacion.sinergiacrm.org
 *   CRM_CLIENT_ID      id del cliente OAuth2 del API V8
 *   CRM_CLIENT_SECRET  su secreto
 *   RESEND_API_KEY     clave de Resend
 *   CUMPLES_FROM       (opcional) remitente; por defecto el de Comunica
 *   CUMPLES_DELEGACIONES  (opcional) igual que --delegaciones
 */

import { readFile, writeFile, appendFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import {
  ahoraEnMadrid, asunto, asuntoPersona, correoHtml, correoPersonaHtml,
  cumpleanosDeHoy, delegacionesActivas, fechaLarga, indiceMonitores,
  textoPersona, textoPlano,
} from './felicitacion.mjs';

const AQUI = dirname(fileURLToPath(import.meta.url));

/** Hora local (Europe/Madrid) a la que debe salir el correo. */
const HORA_DE_ENVIO = 7;

/**
 * Remitente por defecto. Es el dominio ya verificado en Resend para Comunica
 * (ver docs/email-entregabilidad.md), así que SPF/DKIM pasan sin tocar nada.
 * Se puede cambiar con el secreto CUMPLES_FROM o por delegación en el JSON.
 */
const REMITENTE_POR_DEFECTO = 'Cumples MCM <comunica@movimientoconsolacion.com>';

// ─────────────────────────────────────────────────────────────────────────────
// Utilidades
// ─────────────────────────────────────────────────────────────────────────────

/** Lee un fichero JSON del directorio del script. */
async function leerJson(nombre) {
  return JSON.parse(await readFile(join(AQUI, nombre), 'utf8'));
}

/** Argumentos estilo --clave=valor / --bandera. */
function parsearArgs(argv) {
  const opts = { banderas: new Set(), valores: {} };
  for (const arg of argv) {
    if (!arg.startsWith('--')) continue;
    const [clave, ...resto] = arg.slice(2).split('=');
    if (resto.length) opts.valores[clave] = resto.join('=');
    else opts.banderas.add(clave);
  }
  return opts;
}

const log = (...args) => console.log(...args);
const aviso = (...args) => console.warn('⚠️ ', ...args);

/**
 * fetch con reintentos. Solo reintenta lo que tiene sentido reintentar
 * (429 y 5xx, más los fallos de red): un 401 o un 400 no se arreglan
 * insistiendo, y encima retrasarían el correo.
 */
async function fetchConReintentos(url, opciones = {}, intentos = 3) {
  let ultimoError;
  for (let i = 1; i <= intentos; i++) {
    try {
      const resp = await fetch(url, { ...opciones, signal: AbortSignal.timeout(45_000) });
      if (resp.status === 429 || resp.status >= 500) {
        if (i === intentos) return resp;
        const espera = 1500 * i;
        aviso(`HTTP ${resp.status} en ${url} — reintento ${i}/${intentos - 1} en ${espera} ms`);
        await new Promise((r) => setTimeout(r, espera));
        continue;
      }
      return resp;
    } catch (err) {
      ultimoError = err;
      if (i === intentos) break;
      await new Promise((r) => setTimeout(r, 1500 * i));
    }
  }
  throw ultimoError;
}

// ─────────────────────────────────────────────────────────────────────────────
// SinergiaCRM (API V8, JSON:API)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Token OAuth2 por client_credentials.
 *
 * Se intenta primero en JSON y, si el CRM lo rechaza, en formulario: según la
 * versión de SuiteCRM acepta uno u otro y no merece la pena adivinar.
 */
async function tokenCrm({ url, clientId, clientSecret }) {
  const cuerpo = {
    grant_type: 'client_credentials',
    client_id: clientId,
    client_secret: clientSecret,
    scope: '',
  };

  const intentos = [
    { 'Content-Type': 'application/json', Accept: 'application/json' },
    { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
  ];

  let ultimo = '';
  for (const headers of intentos) {
    const body = headers['Content-Type'] === 'application/json'
      ? JSON.stringify(cuerpo)
      : new URLSearchParams(cuerpo).toString();

    const resp = await fetchConReintentos(`${url}/Api/access_token`, { method: 'POST', headers, body });
    const texto = await resp.text();
    if (resp.ok) {
      const datos = JSON.parse(texto);
      if (!datos.access_token) throw new Error(`El CRM no devolvió access_token: ${texto.slice(0, 300)}`);
      return datos.access_token;
    }
    ultimo = `HTTP ${resp.status} ${texto.slice(0, 300)}`;
  }

  throw new Error(`No se pudo autenticar en el CRM (${ultimo})`);
}

/**
 * Recorre todas las páginas de un módulo y devuelve los atributos planos,
 * con el id incluido (que en JSON:API va fuera de "attributes").
 */
async function listarModulo({ url, token }, modulo, filtros = {}) {
  const registros = [];
  let pagina = 1;
  let totalPaginas = 1;

  do {
    const params = new URLSearchParams();
    for (const [campo, [operador, valor]] of Object.entries(filtros)) {
      params.set(`filter[${campo}][${operador}]`, String(valor));
    }
    params.set('page[number]', String(pagina));
    params.set('page[size]', '50');

    const resp = await fetchConReintentos(`${url}/Api/V8/module/${modulo}?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/vnd.api+json' },
    });
    const texto = await resp.text();
    if (!resp.ok) {
      throw new Error(`El CRM falló al listar ${modulo} (HTTP ${resp.status}): ${texto.slice(0, 400)}`);
    }

    const datos = JSON.parse(texto);
    for (const fila of datos.data ?? []) {
      registros.push({ id: fila.id, ...(fila.attributes ?? {}) });
    }

    // El CRM deja de mandar total-pages cuando no hay más; el `|| pagina` evita
    // quedarse dando vueltas si algún día cambia el formato.
    totalPaginas = Number(datos.meta?.['total-pages'] ?? datos.meta?.total_pages ?? pagina) || pagina;
    pagina += 1;
  } while (pagina <= totalPaginas && pagina <= 200);

  return registros;
}

// ─────────────────────────────────────────────────────────────────────────────
// Resend
// ─────────────────────────────────────────────────────────────────────────────

async function enviarConResend({ apiKey, from, to, subject, html, text }) {
  const resp = await fetchConReintentos('https://api.resend.com/emails', {
    method: 'POST',
    headers: { Authorization: `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ from, to, subject, html, text }),
  });

  const texto = await resp.text();
  if (!resp.ok) throw new Error(`Resend rechazó el envío (HTTP ${resp.status}): ${texto.slice(0, 400)}`);
  return JSON.parse(texto || '{}');
}

// ─────────────────────────────────────────────────────────────────────────────
// Modos auxiliares
// ─────────────────────────────────────────────────────────────────────────────

/** Comprueba que todos los GIFs de la bolsa siguen cargando. */
async function verificarGifs() {
  const { gifs } = await leerJson('gifs.json');
  let malos = 0;

  for (const g of gifs) {
    try {
      const resp = await fetchConReintentos(g.url, { method: 'GET' }, 2);
      const tipo = resp.headers.get('content-type') ?? '';
      if (!resp.ok || !tipo.includes('gif')) {
        aviso(`${resp.status} ${tipo || 'sin tipo'} — ${g.url}`);
        malos += 1;
      } else {
        log(`✓ ${g.url.split('/').pop()}`);
      }
    } catch (err) {
      aviso(`error de red — ${g.url}: ${err.message}`);
      malos += 1;
    }
  }

  log(`\n${gifs.length - malos}/${gifs.length} GIFs correctos.`);
  return malos === 0 ? 0 : 1;
}

/** Datos de mentira para poder ver el correo sin tocar el CRM. */
function datosDemo() {
  return [
    {
      id: '00000533-dc25-a5f7-6175-68d10443ffde',
      nombre: 'Mercedes Martí París',
      nombreCorto: 'Mercedes',
      nacimiento: '2006-08-18',
      edad: 20,
      genero: 'female',
      movil: '600123456',
      email: 'mercedes@example.org',
      etapa: 'COM',
      grupo: 'COMser',
      monitorDesde: '2022-09-01',
    },
    {
      id: '0000009f-6d50-d6b7-7658-68d104f5bad7',
      nombre: 'Jaime Pardo Aragonés',
      nombreCorto: 'Jaime',
      nacimiento: '1998-08-18',
      edad: 28,
      genero: 'male',
      movil: '',
      email: 'jaime@example.org',
      etapa: 'LC',
      grupo: '',
      monitorDesde: '2019-09-01',
    },
  ];
}

/** Escribe en el resumen del job de GitHub Actions, si estamos ahí. */
async function resumen(texto) {
  if (!process.env.GITHUB_STEP_SUMMARY) return;
  await appendFile(process.env.GITHUB_STEP_SUMMARY, `${texto}\n`);
}

// ─────────────────────────────────────────────────────────────────────────────
// Programa principal
// ─────────────────────────────────────────────────────────────────────────────

async function principal() {
  const { banderas, valores } = parsearArgs(process.argv.slice(2));

  if (banderas.has('verificar-gifs')) return verificarGifs();

  const config = await leerJson('delegaciones.json');
  const { gifs } = await leerJson('gifs.json');

  const modoDemo = banderas.has('demo');
  const seco = banderas.has('dry-run') || modoDemo;
  const forzarHora = banderas.has('forzar-hora') || modoDemo || Boolean(valores.fecha);

  const ahora = ahoraEnMadrid();
  const hoy = valores.fecha ?? ahora.fecha;

  if (!/^\d{4}-\d{2}-\d{2}$/.test(hoy)) {
    throw new Error(`--fecha tiene que ser YYYY-MM-DD, no "${hoy}"`);
  }

  // El cron dispara a las 05:00 y 06:00 UTC para cubrir el horario de verano y
  // el de invierno; solo una de las dos cae a las 7 en Madrid, y es la que pasa.
  if (!forzarHora && ahora.hora !== HORA_DE_ENVIO) {
    log(`En Madrid son las ${ahora.hora}:00, no las ${HORA_DE_ENVIO}:00. No toca; salgo sin hacer nada.`);
    return 0;
  }

  const delegaciones = delegacionesActivas(
    config,
    valores.delegaciones ?? process.env.CUMPLES_DELEGACIONES ?? null,
  );

  if (delegaciones.length === 0) {
    aviso('Ninguna delegación activa con destinatarios. Revisa delegaciones.json.');
    return 0;
  }

  log(`Fecha: ${hoy} (${fechaLarga(hoy)})`);
  log(`Delegaciones: ${delegaciones.map((d) => d.clave).join(', ')}`);

  // ── Datos ────────────────────────────────────────────────────────────────
  let relaciones = [];
  let contactosDeHoy = [];

  if (!modoDemo) {
    const url = (process.env.CRM_URL ?? '').replace(/\/+$/, '');
    const clientId = process.env.CRM_CLIENT_ID ?? '';
    const clientSecret = process.env.CRM_CLIENT_SECRET ?? '';
    if (!url || !clientId || !clientSecret) {
      throw new Error('Faltan CRM_URL, CRM_CLIENT_ID o CRM_CLIENT_SECRET.');
    }

    const token = await tokenCrm({ url, clientId, clientSecret });
    const crm = { url, token };

    // Una sola consulta para todas las delegaciones: son ~165 relaciones en
    // total, así que sale más barato traerlas y filtrar aquí que preguntar por
    // delegación. Además el campo del usuario asignado no se puede filtrar en
    // la API (es un campo "relate" y la consulta revienta con error de BD).
    relaciones = await listarModulo(crm, 'stic_Contacts_Relationships', {
      relationship_type: ['eq', 'monitor'],
      active: ['eq', 1],
    });
    log(`Relaciones de monitor/a activas en todo el CRM: ${relaciones.length}`);

    // Cumpleaños de hoy: LIKE '%-MM-DD' sobre birthdate. Devuelve pocas filas.
    contactosDeHoy = await listarModulo(crm, 'Contacts', {
      birthdate: ['like', `%-${hoy.slice(5)}`],
    });
    log(`Personas que cumplen hoy (todas las delegaciones): ${contactosDeHoy.length}`);
  }

  // ── Un correo por delegación ─────────────────────────────────────────────
  let enviados = 0;
  const lineasResumen = [`## 🎂 Cumpleaños ${fechaLarga(hoy)}`, ''];

  for (const delegacion of delegaciones) {
    const cumples = modoDemo
      ? datosDemo()
      : cumpleanosDeHoy(contactosDeHoy, indiceMonitores(relaciones, delegacion), hoy);

    if (cumples.length === 0) {
      log(`· ${delegacion.nombre}: hoy nadie. No se manda nada.`);
      lineasResumen.push(`- **${delegacion.nombre}**: hoy nadie 🙂`);
      continue;
    }

    const asuntoCorreo = asunto(cumples, delegacion.nombre);
    const html = correoHtml(cumples, delegacion, hoy, gifs);
    const texto = textoPlano(cumples, delegacion, hoy);

    log(`· ${delegacion.nombre}: ${cumples.map((c) => `${c.nombre} (${c.edad ?? '?'})`).join(', ')}`);
    lineasResumen.push(
      `- **${delegacion.nombre}**: ${cumples.map((c) => `${c.nombre} (${c.edad ?? '?'})`).join(', ')}`,
    );

    // Copias locales para poder mirar cómo quedaron (el workflow las sube como
    // artefacto): el aviso a la delegación y la felicitación de la primera
    // persona, que es la que sirve de muestra de "cómo lo ve quien cumple".
    const ruta = join(AQUI, `vista-previa-${delegacion.clave}.html`);
    await writeFile(ruta, html, 'utf8');

    // ── Felicitación directa a cada persona ────────────────────────────────
    // Se manda antes que el aviso a la delegación: si algo falla, lo que no
    // queremos perder es precisamente la felicitación a quien cumple.
    const felicitarPersona = delegacion.felicitar_a_la_persona !== false;
    const personales = [];
    if (felicitarPersona) {
      for (const c of cumples) {
        if (!c.email) {
          log(`  · ${c.nombre}: sin correo en el CRM, no se le puede escribir.`);
          continue;
        }
        personales.push({
          c,
          asunto: asuntoPersona(c),
          html: correoPersonaHtml(c, delegacion, hoy, gifs),
          texto: textoPersona(c, delegacion, hoy),
        });
      }
      if (personales.length > 0) {
        await writeFile(
          join(AQUI, `vista-previa-${delegacion.clave}-persona.html`),
          personales[0].html,
          'utf8',
        );
      }
    }

    if (seco) {
      log(`  (dry-run) no se envía nada. Vistas previas en ${AQUI}`);
      if (felicitarPersona) {
        log(`  (dry-run) se felicitaría directamente a: ${personales.map((p) => p.c.email).join(', ') || '(nadie: sin correo)'}`);
      }
      continue;
    }

    const apiKey = process.env.RESEND_API_KEY ?? '';
    const from = delegacion.remite ?? process.env.CUMPLES_FROM ?? REMITENTE_POR_DEFECTO;
    if (!apiKey) throw new Error('Falta RESEND_API_KEY.');

    // --destinatario es SOLO para probar: manda TODO (aviso y felicitaciones) a
    // quien digas, sin escribir ni a la delegación ni a los monitores. El cron
    // nunca lo usa, así que en producción sale siempre a quien toca.
    const prueba = valores.destinatario
      ? valores.destinatario.split(',').map((s) => s.trim()).filter(Boolean)
      : null;
    if (prueba) {
      aviso(`Envío de PRUEBA: todo va a ${prueba.join(', ')}. No se escribe a nadie más.`);
    }

    for (const p of personales) {
      const res = await enviarConResend({
        apiKey,
        from,
        to: prueba ?? [p.c.email],
        subject: p.asunto,
        html: p.html,
        text: p.texto,
      });
      log(`  🎁 felicitado ${p.c.nombre} <${prueba ? prueba.join(', ') : p.c.email}> (id ${res.id ?? '?'})`);
      enviados += 1;
    }

    const res = await enviarConResend({
      apiKey,
      from,
      to: prueba ?? delegacion.destinatarios,
      subject: asuntoCorreo,
      html,
      text: texto,
    });
    log(`  ✉️  aviso a ${(prueba ?? delegacion.destinatarios).join(', ')} (id ${res.id ?? '?'})`);
    enviados += 1;
  }

  if (seco) lineasResumen.push('', '_Ejecución en seco: no se ha enviado ningún correo._');
  await resumen(lineasResumen.join('\n'));

  log(`\nListo. Correos enviados: ${enviados}.`);
  return 0;
}

principal()
  .then((codigo) => process.exit(codigo ?? 0))
  .catch((err) => {
    console.error(`\n❌ ${err.message}`);
    process.exit(1);
  });
