/**
 * El informe del Guardián: Markdown para el resumen del job y HTML para el correo.
 * ---------------------------------------------------------------------------
 * Puro: recibe los resultados y devuelve texto. Sin red y sin `process`, para
 * poder probarlo entero.
 */

const ICONO = { ok: '✅', cambios: '📝', aviso: '⚠️', fallo: '❌', saltada: '⏭️' };

/** El estado de una tarea a partir de su resultado. */
export function estadoDe(r) {
  if (r.saltada) return 'saltada';
  if (r.error) return 'fallo';
  if (r.problemas?.length) return 'aviso';
  if (r.detalles?.length) return 'cambios';
  return 'ok';
}

/** ¿Ha ido mal algo? Es lo que decide el código de salida del proceso. */
export function hayFallos(resultados) {
  return resultados.some((r) => r.error);
}

/** ¿Hay algo que un humano deba leer? Decide si se manda correo. */
export function mereceAviso(resultados) {
  return resultados.some((r) => r.error || r.problemas?.length);
}

/**
 * Una línea de titular para el asunto del correo y para el nombre del job.
 * Se escribe pensando en que se lea en la notificación del móvil sin abrirla.
 */
export function titular(resultados) {
  const fallos = resultados.filter((r) => r.error).length;
  const avisos = resultados.filter((r) => !r.error && r.problemas?.length).length;
  const cambios = resultados.reduce((n, r) => n + (r.detalles?.length ?? 0), 0);

  if (fallos) return `${fallos} ${fallos === 1 ? 'tarea ha fallado' : 'tareas han fallado'}`;
  if (avisos) return `${avisos} ${avisos === 1 ? 'tarea con avisos' : 'tareas con avisos'}`;
  if (cambios) return `todo bien · ${cambios} ${cambios === 1 ? 'cambio' : 'cambios'}`;
  return 'todo bien · sin cambios';
}

/** El informe en Markdown, para $GITHUB_STEP_SUMMARY. */
export function aMarkdown({ resultados, contexto }) {
  const l = [];
  l.push(`## 🌙 Guardián Nocturno — ${titular(resultados)}`);
  l.push('');
  l.push(`*${contexto.cuando}* · ${contexto.llamadas} llamadas al CRM · ${contexto.duracionSeg} s`
    + (contexto.secoDePrueba ? ' · **prueba en seco: no se ha escrito nada**' : ''));
  l.push('');

  l.push('| | Tarea | Resultado |');
  l.push('|---|---|---|');
  for (const r of resultados) {
    const estado = estadoDe(r);
    const texto = r.error ? 'ha fallado' : (r.saltada ? (r.motivo ?? 'saltada') : (r.resumen ?? '—'));
    l.push(`| ${ICONO[estado]} | ${r.titulo ?? r.clave} | ${escaparTabla(texto)} |`);
  }
  l.push('');

  for (const r of resultados) {
    const tieneAlgo = r.error || r.problemas?.length || r.detalles?.length;
    if (!tieneAlgo) continue;

    l.push(`### ${ICONO[estadoDe(r)]} ${r.titulo ?? r.clave}`);
    l.push('');

    if (r.error) {
      l.push('**Ha fallado:**');
      l.push('');
      l.push('```');
      l.push(r.error.mensaje);
      if (r.error.pila) l.push('', r.error.pila);
      l.push('```');
      l.push('');
    }
    for (const p of r.problemas ?? []) {
      l.push(`- ⚠️ ${p}`);
    }
    if (r.problemas?.length) l.push('');

    // Los detalles se plegan: en una noche movida pueden ser cincuenta líneas y
    // no queremos que tapen los avisos, que es lo que hay que leer. Cada tarea
    // los llama como son: la de recuentos hace "cambios", la de revisión no
    // toca nada y decir "cambios" ahí sería mentir.
    if (r.detalles?.length) {
      const etiqueta = r.etiquetaDetalles
        ?? (r.detalles.length === 1 ? 'cambio' : 'cambios');
      l.push('<details>');
      l.push(`<summary>${r.detalles.length} ${etiqueta}</summary>`);
      l.push('');
      for (const d of r.detalles) l.push(`- ${d}`);
      l.push('');
      l.push('</details>');
      l.push('');
    }
  }

  if (contexto.avisosCrm?.length) {
    l.push('<details>');
    l.push(`<summary>${contexto.avisosCrm.length} reintentos contra el CRM</summary>`);
    l.push('');
    for (const a of contexto.avisosCrm) l.push(`- ${a}`);
    l.push('');
    l.push('</details>');
  }

  return l.join('\n');
}

/** El mismo informe en texto plano, para el cuerpo del correo. */
export function aTexto({ resultados, contexto }) {
  const l = [`GUARDIÁN NOCTURNO — ${titular(resultados)}`, ''];
  l.push(`${contexto.cuando} · ${contexto.llamadas} llamadas al CRM · ${contexto.duracionSeg} s`);
  if (contexto.secoDePrueba) l.push('PRUEBA EN SECO: no se ha escrito nada.');
  l.push('');

  for (const r of resultados) {
    const estado = estadoDe(r).toUpperCase();
    l.push(`[${estado}] ${r.titulo ?? r.clave}: ${r.error ? 'ha fallado' : (r.resumen ?? '—')}`);
    if (r.error) l.push(`         ${r.error.mensaje}`);
    for (const p of r.problemas ?? []) l.push(`         · ${p}`);
  }

  if (contexto.enlaceEjecucion) {
    l.push('', `Detalle completo: ${contexto.enlaceEjecucion}`);
  }
  return l.join('\n');
}

/** Y en HTML, sencillito: lo va a leer un móvil a las 8 de la mañana. */
export function aHtml({ resultados, contexto }) {
  const color = { ok: '#2f9e44', cambios: '#1c6fb3', aviso: '#c2410c', fallo: '#dc2626', saltada: '#6b7280' };
  const filas = resultados.map((r) => {
    const estado = estadoDe(r);
    const texto = r.error ? 'ha fallado' : (r.saltada ? (r.motivo ?? 'saltada') : (r.resumen ?? '—'));
    const extra = [
      ...(r.error ? [`<div style="color:#b91c1c;font-size:13px;margin-top:4px">${esc(r.error.mensaje)}</div>`] : []),
      ...(r.problemas ?? []).map((p) => `<div style="color:#92400e;font-size:13px;margin-top:4px">⚠️ ${esc(p)}</div>`),
    ].join('');
    return `<tr>
      <td style="padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:top">
        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color[estado]}"></span>
      </td>
      <td style="padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:top">
        <strong style="font-size:14px">${esc(r.titulo ?? r.clave)}</strong>
        <div style="color:#4b5563;font-size:13px;margin-top:2px">${esc(texto)}</div>
        ${extra}
      </td>
    </tr>`;
  }).join('');

  return `<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:600px;margin:0 auto;color:#1f2937">
    <h2 style="font-size:18px;margin:0 0 4px">🌙 Guardián Nocturno</h2>
    <p style="margin:0 0 16px;color:#6b7280;font-size:13px">
      ${esc(titular(resultados))} · ${esc(contexto.cuando)}
      ${contexto.secoDePrueba ? ' · <strong>prueba en seco</strong>' : ''}
    </p>
    <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:10px">
      ${filas}
    </table>
    ${contexto.enlaceEjecucion
      ? `<p style="margin:16px 0 0;font-size:13px"><a href="${esc(contexto.enlaceEjecucion)}" style="color:#1c6fb3">Ver la ejecución completa en GitHub</a></p>`
      : ''}
  </div>`;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

/** En una tabla de Markdown una barra vertical parte la fila. */
function escaparTabla(s) {
  return String(s ?? '').replace(/\|/g, '\\|').replace(/\n+/g, ' ');
}
