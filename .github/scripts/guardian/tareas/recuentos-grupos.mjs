/**
 * TAREA · Recuentos y monitores en la ficha del grupo
 * ---------------------------------------------------------------------------
 * Lleva al registro del grupo cuánta gente tiene y quiénes son sus monitores,
 * para que las pantallas que NO traen la lista de personas puedan decirlo sin
 * una consulta por grupo. El por qué está en
 * `docs/comunica/PASAR-LISTA-RECUENTOS.md`.
 *
 * Aquí sí se hace una llamada por grupo, y da igual: a la 1:30 nadie está
 * esperando delante de una pantalla. Eso es justamente lo que hace que este
 * trabajo exista en vez de hacerlo el área privada.
 */

import {
  clasificarRelaciones, formatearMonitores, camposQueCambian,
  ventanaDesde, gruposTocados, CAMPO_GRUPO_EN_RELACION,
} from '../logica.mjs';

/** Los nombres de los cuatro campos, en UN sitio. */
export const CAMPOS = {
  nParticipantes: 'ajmcm_n_participantes_c',
  nMonitores: 'ajmcm_n_monitores_c',
  monitores: 'ajmcm_monitores_c',
  recuentoAl: 'ajmcm_recuento_al_c',
};

const MODULO = 'ajmcm_GRUPOS';
const LINK_RELACIONES = 'ajmcm_grupos_stic_contacts_relationships';
const MODULO_RELACIONES = 'stic_Contacts_Relationships';

export const clave = 'recuentos-grupos';
export const titulo = 'Recuentos y monitores de cada grupo';

export async function ejecutar(ctx) {
  const { crm, hoy, secoDePrueba, log } = ctx;

  // Lo primero: ¿existen los campos? Si el módulo no los tiene, la tarea NO
  // intenta escribir y a cambio dice exactamente qué falta y dónde crearlo. Sin
  // esto serían 105 errores iguales del CRM y un informe ilegible.
  const existentes = new Set(await crm.camposDe(MODULO));
  const faltan = Object.values(CAMPOS).filter((c) => !existentes.has(c));
  if (faltan.length) {
    throw new Error(
      `Faltan campos en ${MODULO}: ${faltan.join(', ')}. `
      + 'Se crean a mano en SinergiaCRM (Admin → Estudio → Grupos → Campos); '
      + 'la especificación está en docs/comunica/PASAR-LISTA-RECUENTOS.md §3. '
      + 'Hasta entonces esta tarea no puede escribir nada.',
    );
  }

  // TODOS los grupos, sin filtrar por curso. A propósito: contar un grupo que
  // luego no se enseña no cuesta nada, y filtrar aquí obligaría a mantener la
  // misma regla en dos sitios. Quién se enseña lo decide el área privada.
  const todos = await ctx.grupos();

  // ── Qué grupos toca recalcular ─────────────────────────────────────────
  // `modo` es lo que se pidió; `modoEfectivo` es lo que se acabó haciendo. Se
  // separan porque el suave puede degradar a completo, y el informe tiene que
  // decir lo que PASÓ, no lo que se intentó.
  const modo = ctx.modo === 'soft' ? 'soft' : 'full';
  let modoEfectivo = modo;
  const avisosModo = [];
  let grupos = todos;
  let desde = '';

  if (modo === 'soft') {
    desde = ventanaDesde(hoy, ctx.ventanaDias);
    try {
      const relaciones = await crm.listar(MODULO_RELACIONES, {
        campos: ['id', CAMPO_GRUPO_EN_RELACION, 'date_modified'],
        filtro: { date_modified: { gte: desde } },
      });
      const { ids, sinGrupo } = gruposTocados(relaciones);
      grupos = todos.filter((g) => ids.has(g.id));

      // Ids tocados que no son de ningún grupo conocido: normalmente un grupo de
      // otra delegación o uno borrado. No es un fallo, pero se dice.
      const desconocidos = [...ids].filter((id) => !todos.some((g) => g.id === id)).length;
      log(`modo suave · ${relaciones.length} relaciones tocadas desde ${desde}`
        + ` → ${grupos.length} grupos que recalcular`
        + (sinGrupo ? ` (${sinGrupo} relaciones sin grupo, normal)` : ''));
      if (desconocidos) avisosModo.push(`${desconocidos} grupos tocados que no están en la lista de grupos`);
    } catch (err) {
      // Si el filtro falla, se cae hacia la pasada COMPLETA y no hacia no hacer
      // nada: el modo suave es una optimización, y una optimización que se rompe
      // tiene que degradar al camino seguro, no dejar los números sin tocar.
      grupos = todos;
      modoEfectivo = 'full';
      // En el aviso va el motivo CORTO: esto acaba en un correo, y la URL entera
      // con el filtro codificado ocupa media pantalla. La larga queda en el log.
      avisosModo.push(`el modo suave no se pudo hacer (${primeraLinea(err.message)});`
        + ' se ha hecho la pasada completa, así que los números están bien igual');
      log(`modo suave roto, se pasa a completo: ${err.message}`);
    }
  } else {
    log(`modo completo · ${todos.length} grupos que revisar`);
  }

  const recuentos = new Map();
  const detalles = [];
  const problemas = [...avisosModo];
  let escritos = 0;

  // El sello se calcula UNA vez para toda la pasada: si cada grupo llevara su
  // propio segundo, dos grupos de la misma noche parecerían de momentos
  // distintos y no se podría decir "esto es de la pasada del día 23".
  const sello = hoy.toISOString().slice(0, 19).replace('T', ' ');

  for (const grupo of grupos) {
    const etiqueta = String(grupo.code ?? '').trim() || String(grupo.name ?? '').trim() || grupo.id;
    try {
      const relaciones = await ctx.relacionesDe(grupo.id, MODULO, LINK_RELACIONES);
      const { nParticipantes, nMonitores, nombresMonitores } = clasificarRelaciones(relaciones, hoy);
      const monitores = formatearMonitores(nombresMonitores);

      recuentos.set(grupo.id, { nParticipantes, nMonitores, monitores });

      const cambios = camposQueCambian(grupo, { nParticipantes, nMonitores, monitores }, { sello, campos: CAMPOS });
      if (Object.keys(cambios).length === 0) continue;

      if (!secoDePrueba) await crm.actualizar(MODULO, grupo.id, cambios);
      escritos += 1;
      detalles.push(
        `${etiqueta}: ${plural(nParticipantes, 'participante', 'participantes')} · ${plural(nMonitores, 'monitor', 'monitores')}`
        + (monitores ? ` (${monitores})` : ''),
      );
    } catch (err) {
      // Un grupo que falla no se lleva por delante a los otros 104. Se apunta y
      // se sigue; el informe lo cantará al final.
      problemas.push(`${etiqueta}: ${err.message}`);
    }
  }

  // Los recuentos quedan en el contexto para que otras tareas los usen sin
  // volver a pedir nada al CRM.
  ctx.compartir('recuentos', recuentos);

  // El resumen dice el modo y, en suave, CUÁNTOS NO se han mirado. Sin ese dato
  // "3 grupos revisados" se lee como si hubiera solo tres grupos.
  const sinMirar = todos.length - grupos.length;
  const cabeza = modoEfectivo === 'soft'
    ? `suave (desde ${desde}) · ${plural(grupos.length, 'grupo', 'grupos')} con cambios recientes`
      + (sinMirar > 0 ? ` · ${sinMirar} sin recalcular` : '')
    : `completo · ${plural(grupos.length, 'grupo', 'grupos')} revisados`;

  return {
    resumen: secoDePrueba
      ? `${cabeza} · ${escritos} cambiarían (prueba en seco, no se ha escrito nada)`
      : `${cabeza} · ${escritos} actualizados`,
    detalles,
    problemas,
  };
}

/**
 * "1 participante" / "2 participantes". Se dan las DOS formas y no se añade una
 * "s": en español el plural de "monitor" es "monitores", no "monitors". Esto se
 * lee cada noche, así que que esté bien escrito.
 */
function plural(n, singular, plural_) {
  return `${n} ${n === 1 ? singular : plural_}`;
}

/**
 * El motivo de un error, corto y sin la URL.
 *
 * El mensaje del cliente del CRM trae la ruta entera con el filtro codificado, y
 * eso en un correo es media pantalla de ruido que tapa lo que importa.
 */
function primeraLinea(mensaje, max = 120) {
  const limpio = String(mensaje ?? '').split('\n')[0].replace(/\s*https?:\/\/\S+|\s*\/Api\/\S+/g, '').trim();
  const texto = limpio || String(mensaje ?? '').split('\n')[0].trim();
  return texto.length > max ? `${texto.slice(0, max)}…` : texto;
}
