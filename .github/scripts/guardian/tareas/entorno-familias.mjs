/**
 * TAREA · La familia al derecho (entorno personal)
 * ---------------------------------------------------------------------------
 * Al guardar una inscripción, el CRM ata al tutor con el crío AL REVÉS y sin
 * «Firmante autorizado» (desde el 25/09/2026; ver `planEntorno()` en
 * logica.mjs). comunicaFormularios lo endereza justo después del alta
 * (`entornoEndereza()` de su proxy), pero si la familia cierra la página se
 * queda así. Esta tarea es la red: mira las relaciones de padre, madre o tutor/a
 * creadas en los últimos días y
 *
 *   · gira las que están al revés (y les marca el firmante),
 *   · borra (borrado lógico) las que repiten una buena,
 *   · marca el firmante en las buenas que no lo tienen,
 *   · y avisa de las que no sabe leer (sin fechas, o atado consigo mismo).
 *
 * Quién es el crío lo dice la fecha de nacimiento: una llamada por persona, así
 * que solo se mira una ventana (la del modo suave, o 30 días en el completo).
 * Para saber si la buena ya existe se leen TODAS (unas 12 páginas).
 *
 * En el informe, solo ids y números: nada de nombres de menores.
 */

import { ENTORNO_A, ENTORNO_B, TIPOS_TUTOR, planEntorno, ventanaDesde } from '../logica.mjs';

export const clave = 'entorno-familias';
export const titulo = 'La familia al derecho (entorno personal)';

const MODULO = 'stic_Personal_Environment';
const CAMPOS = ['id', 'relationship_type', 'authorized_signer', 'date_entered', ENTORNO_A, ENTORNO_B];
const DIAS_COMPLETO = 30;

export async function ejecutar(ctx) {
  const { crm, log } = ctx;
  const dias = ctx.modo === 'full' ? DIAS_COMPLETO : ctx.ventanaDias;
  const desde = ventanaDesde(ctx.hoy, dias);

  const todos = await crm.listar(MODULO, { campos: CAMPOS });
  const revisar = todos.filter((r) =>
    TIPOS_TUTOR.includes(r.relationship_type) && String(r.date_entered ?? '').slice(0, 10) >= desde);
  log(`${todos.length} relaciones; ${revisar.length} de padre, madre o tutor/a desde el ${desde}`);

  // Fechas y nombres de quienes salen en la ventana: una llamada por persona.
  const personas = new Map();
  for (const r of revisar) {
    for (const id of [r[ENTORNO_A], r[ENTORNO_B]]) {
      if (!id || personas.has(id)) continue;
      const c = await crm.obtener('Contacts', id, ['first_name', 'last_name', 'birthdate']);
      personas.set(id, {
        birthdate: c?.birthdate ?? '',
        nombre: `${c?.first_name ?? ''} ${c?.last_name ?? ''}`.replace(/\s+/g, ' ').trim(),
      });
    }
  }

  const ops = planEntorno(todos, revisar, personas);
  const cuenta = { girar: 0, borrar: 0, firmante: 0 };
  const detalles = [];
  const problemas = [];

  for (const o of ops) {
    if (o.op === 'dudosa') {
      problemas.push(`Relación ${o.id}: ${o.motivo}. Mírala a mano en el CRM.`);
      continue;
    }
    const atributos = o.op === 'girar' ? o.campos
      : o.op === 'borrar' ? { deleted: 1 }
        : { authorized_signer: 1 };
    if (!ctx.secoDePrueba) await crm.actualizar(MODULO, o.id, atributos);
    cuenta[o.op] += 1;
    detalles.push(`${o.id}: ${o.op === 'girar' ? 'girada y con firmante' : o.op === 'borrar' ? 'borrada (repetía una buena)' : 'firmante autorizado marcado'}`);
  }

  // Si esto escribe cada noche, el CRM sigue creándolas al revés y el
  // formulario no las está cogiendo: que se vea en el titular.
  if (cuenta.girar + cuenta.borrar > 0) {
    problemas.push(`${cuenta.girar} al revés y ${cuenta.borrar} repetidas que el formulario no había enderezado `
      + '(¿la familia cerró la página? ¿falla `alta_entorno`?).');
  }

  const total = cuenta.girar + cuenta.borrar + cuenta.firmante;
  return {
    resumen: total === 0 ? 'todas al derecho'
      : `${cuenta.girar} giradas, ${cuenta.borrar} borradas, ${cuenta.firmante} con firmante`,
    detalles,
    problemas,
  };
}
