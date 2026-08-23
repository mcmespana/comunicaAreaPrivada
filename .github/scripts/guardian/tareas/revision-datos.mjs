/**
 * TAREA · Revisión de datos que rompen Pasar Lista
 * ---------------------------------------------------------------------------
 * No escribe nada: mira y canta. Son los problemas que en el CRM cuestan de ver
 * porque hay que cruzar dos módulos, y que en el área privada se notan como
 * "falta un grupo" o "esta lista sale vacía".
 *
 * Reutiliza los recuentos que ya calculó `recuentos-grupos`, así que no cuesta
 * ni una llamada más. Si esa tarea no se ha ejecutado (`--tareas=revision-datos`
 * a secas), se apaña con lo que puede y lo dice.
 */

import { revisarDatos, cursoDe } from '../logica.mjs';

export const clave = 'revision-datos';
export const titulo = 'Datos que hacen falta revisar';

export async function ejecutar(ctx) {
  const { hoy, log } = ctx;

  const grupos = await ctx.grupos();
  const recuentos = ctx.compartido('recuentos') ?? new Map();
  const curso = cursoDe(hoy);

  if (recuentos.size === 0) {
    log('sin recuentos en memoria: solo se revisa lo que se puede ver en el propio grupo');
  }

  const { sinCodigo, sinMonitor, sinNadie, invisiblesEnPasarLista } = revisarDatos(grupos, recuentos, { curso });

  const detalles = [];
  const problemas = [];

  // Este es el gordo, y por eso va como PROBLEMA y no como detalle: un grupo
  // que Pasar Lista no ve es un grupo del que nadie puede pasar lista.
  if (invisiblesEnPasarLista.length) {
    problemas.push(
      `${plural(invisiblesEnPasarLista.length, 'grupo', 'grupos')} NO ${invisiblesEnPasarLista.length === 1 ? 'lo ve' : 'los ve'} Pasar Lista porque su campo `
      + `"cursos_c" no contiene "${curso}". En el CRM ese campo lleva el curso escolar `
      + `("1º ESO", "Adultos"), no el año académico, así que el filtro de `
      + `sticpa_pl_groups() los descarta a todos. Grupos: `
      + `${resumirLista(invisiblesEnPasarLista)}`,
    );
  }

  if (sinMonitor.length) {
    detalles.push(`${plural(sinMonitor.length, 'grupo', 'grupos')} con participantes y SIN monitor vigente: ${resumirLista(sinMonitor)}`);
  }
  if (sinNadie.length) {
    detalles.push(`${plural(sinNadie.length, 'grupo', 'grupos')} sin nadie (ni monitores ni participantes vigentes): ${resumirLista(sinNadie)}`);
  }
  if (sinCodigo.length) {
    detalles.push(`${plural(sinCodigo.length, 'grupo', 'grupos')} sin código corto: ${resumirLista(sinCodigo)}`);
  }

  const total = detalles.length + problemas.length;
  return {
    resumen: total === 0 ? 'nada que revisar' : `${total === 1 ? '1 cosa' : `${total} cosas`} que mirar`,
    // Esta tarea no escribe nada, así que sus detalles no son "cambios".
    etiquetaDetalles: 'cosas que mirar',
    detalles,
    problemas,
  };
}

/** Una lista larga se recorta: un informe con 80 códigos no se lee. */
function resumirLista(items, max = 12) {
  if (items.length <= max) return items.join(', ');
  return `${items.slice(0, max).join(', ')} … y ${items.length - max} más`;
}

/**
 * "1 participante" / "2 participantes". Se dan las DOS formas y no se añade una
 * "s": en español el plural de "monitor" es "monitores", no "monitors". Esto se
 * lee cada noche, así que que esté bien escrito.
 */
function plural(n, singular, plural_) {
  return `${n} ${n === 1 ? singular : plural_}`;
}
