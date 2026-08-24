#!/usr/bin/env node
/**
 * GUARDIÁN NOCTURNO DEL CRM
 * ===========================================================================
 * Pasa cada noche por SinergiaCRM, hace el mantenimiento que el área privada no
 * puede permitirse en caliente, y canta si algo va mal.
 *
 * Hoy hace dos cosas:
 *   · recuentos-grupos  — lleva al grupo cuánta gente tiene y quiénes son sus
 *                         monitores (docs/comunica/PASAR-LISTA-RECUENTOS.md)
 *   · revision-datos    — mira y avisa de lo que rompe Pasar Lista
 *
 * ── Añadir una tarea ───────────────────────────────────────────────────────
 * Crea `tareas/lo-tuyo.mjs` exportando `clave`, `titulo` y
 * `async ejecutar(ctx)`, y añádelo a la lista TAREAS de abajo. El contrato:
 *
 *   ctx.crm                       cliente del CRM (listar/relaciones/actualizar)
 *   ctx.grupos()                  todos los grupos, cacheado para toda la pasada
 *   ctx.relacionesDe(id, mod, lk) relaciones de un registro, cacheado
 *   ctx.compartir(k, v) / ctx.compartido(k)   pasar datos a otras tareas
 *   ctx.hoy                       el instante de la pasada (uno para todas)
 *   ctx.modo                      'soft' (solo lo que ha cambiado) | 'full'
 *   ctx.ventanaDias               días hacia atrás que mira el modo suave
 *   ctx.secoDePrueba              true = mirar sin escribir
 *   ctx.log(texto)                traza en el log del job
 *
 *   devuelve { resumen, detalles: [], problemas: [] }
 *
 * Reglas de la casa:
 *   · Una tarea que lanza una excepción NO tumba a las demás: se apunta y se
 *     sigue. Al final el proceso sale con código 1 para que el job salga rojo.
 *   · Se escribe SOLO lo que cambia. Reescribir lo igual llena el registro de
 *     auditoría del CRM de ruido y luego no se ve quién tocó algo de verdad.
 *   · Nada de datos personales en el informe más allá del nombre de pila de un
 *     monitor: esto acaba en un correo y en los logs de GitHub.
 *
 * ── Cómo se ejecuta ────────────────────────────────────────────────────────
 *   node .github/scripts/guardian/guardian.mjs [opciones]
 *     --tareas=a,b        solo esas (por defecto, todas)
 *     --dry-run           consulta y calcula, pero no escribe en el CRM
 *     --avisar-siempre    manda el correo aunque todo haya ido bien
 *     --modo=auto         auto | soft | full. `auto` (por defecto) mira el día:
 *                         completo viernes y sábado, suave el resto
 *     --ventana-dias=3    los días hacia atrás que mira el modo suave
 *
 * Entorno: CRM_URL, CRM_CLIENT_ID, CRM_CLIENT_SECRET.
 * Para el correo (opcional): RESEND_API_KEY y GUARDIAN_AVISOS_TO.
 */

import { abrirCrm, fetchConReintentos } from './crm.mjs';
import { modoDeLaNoche, MODOS } from './logica.mjs';
import { aMarkdown, aTexto, aHtml, hayFallos, mereceAviso, titular } from './informe.mjs';
import * as recuentosGrupos from './tareas/recuentos-grupos.mjs';
import * as revisionDatos from './tareas/revision-datos.mjs';
import { appendFile, writeFile } from 'node:fs/promises';

/** El registro de tareas. El orden importa: `revision-datos` usa lo que calcula la primera. */
const TAREAS = [recuentosGrupos, revisionDatos];

const CAMPOS_GRUPO = ['id', 'name', 'code', 'level', 'cursos_c', 'assigned_user_id',
  'ajmcm_n_participantes_c', 'ajmcm_n_monitores_c', 'ajmcm_monitores_c', 'ajmcm_recuento_al_c'];

function parsearArgs(argv) {
  const o = {
    tareas: [], dryRun: false, avisarSiempre: false,
    modo: 'auto', ventanaDias: 3,
  };
  for (const a of argv.slice(2)) {
    if (a === '--dry-run') o.dryRun = true;
    else if (a === '--avisar-siempre') o.avisarSiempre = true;
    else if (a.startsWith('--tareas=')) {
      o.tareas = a.slice('--tareas='.length).split(',').map((s) => s.trim()).filter(Boolean);
    } else if (a.startsWith('--modo=')) {
      o.modo = a.slice('--modo='.length).trim();
    } else if (a.startsWith('--ventana-dias=')) {
      o.ventanaDias = Number(a.slice('--ventana-dias='.length)) || 3;
    }
  }
  return o;
}

async function enviarConResend({ apiKey, from, to, subject, html, text }) {
  const resp = await fetchConReintentos('https://api.resend.com/emails', {
    method: 'POST',
    headers: { Authorization: `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ from, to, subject, html, text }),
  });
  const texto = await resp.text();
  if (!resp.ok) throw new Error(`Resend rechazó el envío (HTTP ${resp.status}): ${texto.slice(0, 400)}`);
}

async function principal() {
  const opciones = parsearArgs(process.argv);
  const arranque = Date.now();
  const hoy = new Date();
  const log = (t) => console.log(`   ${t}`);

  // Aquí no hay guarda de la hora: un solo cron, así que no hay ninguna
  // ejecución que descartar, y descartar por hora solo podría hacer que un cron
  // retrasado se saltara la noche entera. Ver la cabecera de logica.mjs.

  // ── Suave o completo ───────────────────────────────────────────────────
  // `auto` decide por el día de la semana EN MADRID: completo el viernes y el
  // sábado, suave el resto. El botón manual manda un modo explícito.
  if (opciones.modo !== 'auto' && !MODOS.includes(opciones.modo)) {
    console.error(`Modo que no existe: ${opciones.modo}. Los que hay: auto, ${MODOS.join(', ')}`);
    return 2;
  }
  const modo = opciones.modo === 'auto' ? modoDeLaNoche(hoy) : opciones.modo;

  const elegidas = opciones.tareas.length
    ? TAREAS.filter((t) => opciones.tareas.includes(t.clave))
    : TAREAS;

  if (opciones.tareas.length) {
    const desconocidas = opciones.tareas.filter((c) => !TAREAS.some((t) => t.clave === c));
    if (desconocidas.length) {
      console.error(`Tareas que no existen: ${desconocidas.join(', ')}`);
      console.error(`Las que hay: ${TAREAS.map((t) => t.clave).join(', ')}`);
      return 2;
    }
  }

  console.log(`🌙 Guardián Nocturno · ${elegidas.length} ${elegidas.length === 1 ? 'tarea' : 'tareas'}`
    + ` · modo ${modo === 'soft' ? 'SUAVE' : 'COMPLETO'}`
    + (opciones.modo === 'auto' ? ' (por el día de la semana)' : '')
    + (opciones.dryRun ? ' · PRUEBA EN SECO' : ''));

  const avisosCrm = [];
  const crm = await abrirCrm({
    url: process.env.CRM_URL,
    clientId: process.env.CRM_CLIENT_ID,
    clientSecret: process.env.CRM_CLIENT_SECRET,
    avisar: (t) => { avisosCrm.push(t); console.warn(`   ⚠ ${t}`); },
  });

  // ── El contexto que comparten las tareas ───────────────────────────────
  // Las cachés son lo que permite que dos tareas usen los mismos datos sin
  // acoplarse ni pedir dos veces lo mismo.
  const compartido = new Map();
  let cacheGrupos = null;
  const cacheRelaciones = new Map();

  const ctx = {
    crm,
    hoy,
    modo,
    ventanaDias: opciones.ventanaDias,
    secoDePrueba: opciones.dryRun,
    log,
    compartir: (k, v) => compartido.set(k, v),
    compartido: (k) => compartido.get(k),
    async grupos() {
      if (!cacheGrupos) cacheGrupos = await crm.listar('ajmcm_GRUPOS', { campos: CAMPOS_GRUPO });
      return cacheGrupos;
    },
    async relacionesDe(id, modulo, link) {
      const clave = `${modulo}/${id}/${link}`;
      if (!cacheRelaciones.has(clave)) cacheRelaciones.set(clave, await crm.relaciones(modulo, id, link));
      return cacheRelaciones.get(clave);
    },
  };

  // ── Las tareas, cada una en su burbuja ─────────────────────────────────
  const resultados = [];
  for (const tarea of elegidas) {
    console.log(`\n▸ ${tarea.titulo}`);
    const t0 = Date.now();
    try {
      const r = await tarea.ejecutar(ctx);
      resultados.push({ clave: tarea.clave, titulo: tarea.titulo, ...r, segundos: (Date.now() - t0) / 1000 });
      console.log(`   ✓ ${r.resumen ?? 'hecho'}`);
      for (const p of r.problemas ?? []) console.warn(`   ⚠ ${p}`);
    } catch (err) {
      // Aquí está la regla: una tarea rota no se lleva por delante a las demás.
      resultados.push({
        clave: tarea.clave,
        titulo: tarea.titulo,
        error: { mensaje: err?.message ?? String(err), pila: (err?.stack ?? '').split('\n').slice(1, 4).join('\n') },
        segundos: (Date.now() - t0) / 1000,
      });
      console.error(`   ✗ ${err?.message ?? err}`);
    }
  }

  // ── El informe ─────────────────────────────────────────────────────────
  const contexto = {
    cuando: new Intl.DateTimeFormat('es-ES', {
      timeZone: 'Europe/Madrid', dateStyle: 'medium', timeStyle: 'short',
    }).format(hoy),
    llamadas: crm.llamadas,
    duracionSeg: Math.round((Date.now() - arranque) / 1000),
    modo,
    secoDePrueba: opciones.dryRun,
    avisosCrm,
    enlaceEjecucion: process.env.GITHUB_SERVER_URL && process.env.GITHUB_REPOSITORY && process.env.GITHUB_RUN_ID
      ? `${process.env.GITHUB_SERVER_URL}/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}`
      : '',
  };

  const markdown = aMarkdown({ resultados, contexto });
  console.log(`\n${'─'.repeat(60)}\n${titular(resultados)}`);

  // El resumen del job: es lo primero que se ve al abrir la ejecución, y lo que
  // hace que no haya que bucear en el log.
  if (process.env.GITHUB_STEP_SUMMARY) {
    await appendFile(process.env.GITHUB_STEP_SUMMARY, `${markdown}\n`);
  }
  // Y el informe crudo como artefacto, para poder mirarlo con calma.
  await writeFile(new URL('./informe-ultimo.json', import.meta.url),
    JSON.stringify({ contexto, resultados }, null, 2));

  // ── El correo ──────────────────────────────────────────────────────────
  // Solo si hay algo que contar, o si se pide expresamente. Un correo cada
  // noche diciendo "todo bien" se deja de leer en una semana, y entonces el
  // que importa también.
  const apiKey = process.env.RESEND_API_KEY;
  const destino = (process.env.GUARDIAN_AVISOS_TO ?? '').trim();
  const debeAvisar = opciones.avisarSiempre || mereceAviso(resultados);

  if (debeAvisar && apiKey && destino) {
    try {
      await enviarConResend({
        apiKey,
        from: process.env.GUARDIAN_FROM || 'Guardián MCM <comunica@movimientoconsolacion.com>',
        to: destino.split(',').map((s) => s.trim()).filter(Boolean),
        subject: `${hayFallos(resultados) ? '❌' : '⚠️'} Guardián Nocturno · ${titular(resultados)}`,
        html: aHtml({ resultados, contexto }),
        text: aTexto({ resultados, contexto }),
      });
      console.log(`Aviso enviado a ${destino}`);
    } catch (err) {
      // Que falle el correo no puede tapar el resultado de la pasada, pero
      // tampoco puede pasar en silencio: el aviso es media función de esto.
      console.error(`No se pudo enviar el aviso: ${err.message}`);
      if (process.env.GITHUB_STEP_SUMMARY) {
        await appendFile(process.env.GITHUB_STEP_SUMMARY,
          `\n> ⚠️ **No se pudo enviar el correo de aviso:** ${err.message}\n`);
      }
      return 1;
    }
  } else if (debeAvisar && !(apiKey && destino)) {
    // Hay algo que contar y no hay por dónde. Se dice en el resumen para que no
    // parezca que el aviso salió.
    const falta = !apiKey ? 'RESEND_API_KEY' : 'GUARDIAN_AVISOS_TO';
    console.warn(`Hay avisos pero no se puede mandar correo: falta ${falta}.`);
    if (process.env.GITHUB_STEP_SUMMARY) {
      await appendFile(process.env.GITHUB_STEP_SUMMARY,
        `\n> ℹ️ Hay algo que contar y **no se ha mandado correo**: falta el secreto \`${falta}\`.\n`);
    }
  }

  return hayFallos(resultados) ? 1 : 0;
}

principal()
  .then((codigo) => process.exit(codigo))
  .catch((err) => {
    // Un fallo ANTES de las tareas (sin credenciales, CRM caído, token
    // rechazado) no tiene informe que escribir, así que se escribe aquí: si no,
    // el job sale rojo y el resumen queda vacío.
    console.error(`\n❌ El Guardián no pudo ni empezar: ${err?.message ?? err}`);
    if (err?.stack) console.error(err.stack);
    if (process.env.GITHUB_STEP_SUMMARY) {
      appendFile(process.env.GITHUB_STEP_SUMMARY,
        `## ❌ Guardián Nocturno — no pudo ni empezar\n\n\`\`\`\n${err?.message ?? err}\n\`\`\`\n`)
        .catch(() => {})
        .finally(() => process.exit(1));
      return;
    }
    process.exit(1);
  });
