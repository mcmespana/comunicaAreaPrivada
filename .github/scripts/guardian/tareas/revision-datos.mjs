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

import { revisarDatos } from '../logica.mjs';
import { CAMPOS } from './recuentos-grupos.mjs';

export const clave = 'revision-datos';
export const titulo = 'Datos que hacen falta revisar';

export async function ejecutar(ctx) {
  const { log } = ctx;

  const grupos = await ctx.grupos();
  const recuentos = ctx.compartido('recuentos') ?? new Map();

  // Para los grupos que no se han recalculado esta noche (el modo suave solo
  // toca los que han cambiado) se usa el número que ya está escrito en el grupo.
  // Así la revisión cubre los 105 en los dos modos.
  const conRecuentoFresco = recuentos.size;
  if (conRecuentoFresco === 0) {
    log('sin recuentos frescos: se revisa con los números guardados en cada grupo');
  } else if (conRecuentoFresco < grupos.length) {
    log(`${conRecuentoFresco} grupos con recuento de esta noche; el resto, con el guardado`);
  }

  const { sinCodigo, sinMonitor, sinNadie } = revisarDatos(grupos, recuentos, CAMPOS);

  const detalles = [];
  const problemas = [];

  // Un grupo con chavales y sin monitor va como PROBLEMA y no como detalle: es
  // una lista que nadie va a pasar el sábado.
  if (sinMonitor.length) {
    problemas.push(`${plural(sinMonitor.length, 'grupo', 'grupos')} con participantes y SIN monitor vigente: ${resumirLista(sinMonitor)}`);
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
