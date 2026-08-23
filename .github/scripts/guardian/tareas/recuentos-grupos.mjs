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

import { clasificarRelaciones, formatearMonitores, camposQueCambian } from '../logica.mjs';

/** Los nombres de los cuatro campos, en UN sitio. */
export const CAMPOS = {
  nParticipantes: 'ajmcm_n_participantes_c',
  nMonitores: 'ajmcm_n_monitores_c',
  monitores: 'ajmcm_monitores_c',
  recuentoAl: 'ajmcm_recuento_al_c',
};

const MODULO = 'ajmcm_GRUPOS';
const LINK_RELACIONES = 'ajmcm_grupos_stic_contacts_relationships';

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
  const grupos = await ctx.grupos();
  log(`${grupos.length} grupos que revisar`);

  const recuentos = new Map();
  const detalles = [];
  const problemas = [];
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

  return {
    resumen: secoDePrueba
      ? `${plural(grupos.length, 'grupo', 'grupos')} revisados · ${escritos} cambiarían (prueba en seco, no se ha escrito nada)`
      : `${plural(grupos.length, 'grupo', 'grupos')} revisados · ${escritos} actualizados`,
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
