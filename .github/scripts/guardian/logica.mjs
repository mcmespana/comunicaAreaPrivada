/**
 * La lógica del Guardián, SIN red.
 * ---------------------------------------------------------------------------
 * Todo lo que se puede decidir con datos ya traídos vive aquí, en funciones
 * puras. Es lo que permite que `guardian.test.mjs` pruebe las reglas de verdad
 * —la vigencia, el recuento, el diff, la guarda de la hora— sin tocar el CRM.
 *
 * Si añades una tarea nueva: la parte que piensa, aquí; la que llama al CRM, en
 * su fichero de `tareas/`.
 */

// ─────────────────────────────────────────────────────────────────────────────
// La hora de aquí
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Hora y minuto en Europe/Madrid para un instante dado.
 *
 * El cron de GitHub es SIEMPRE UTC y España cambia de hora dos veces al año, así
 * que el workflow se lanza a dos horas distintas y esto es lo que decide cuál de
 * las dos es la buena. Se usa `Intl` y no una resta de horas a mano porque el
 * cambio de hora no cae el mismo día cada año.
 */
export function horaEnMadrid(fecha = new Date()) {
  const partes = new Intl.DateTimeFormat('es-ES', {
    timeZone: 'Europe/Madrid',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(fecha);

  const dato = (tipo) => Number(partes.find((p) => p.type === tipo)?.value ?? '0');
  return { hora: dato('hour'), minuto: dato('minute') };
}

/**
 * ¿Toca ejecutar ahora?
 *
 * Se acepta una ventana porque los crons de GitHub Actions **no son puntuales**:
 * en horas de carga se retrasan varios minutos. Sin margen, un retraso de seis
 * minutos significaría no pasar esa noche.
 */
export function tocaAhora({ horaObjetivo, ahora = new Date(), margenMin = 90 }) {
  const { hora, minuto } = horaEnMadrid(ahora);
  const minutosAhora = hora * 60 + minuto;
  const objetivo = horaObjetivo * 60;
  // Distancia circular: a la 01:30 con objetivo 00:00 la diferencia son 90
  // minutos, no 1350.
  const bruta = Math.abs(minutosAhora - objetivo);
  const distancia = Math.min(bruta, 1440 - bruta);
  return distancia <= margenMin;
}

// ─────────────────────────────────────────────────────────────────────────────
// Relaciones persona ↔ grupo
// ─────────────────────────────────────────────────────────────────────────────

/** Los `relationship_type` que nos interesan, con el papel que juegan. */
export const PAPELES = {
  participante_mic_com: 'participante',
  monitor: 'monitor',
};

/**
 * ¿Sigue vigente esta relación?
 *
 * MISMA regla que `sticpa_pl_group_people()` en PHP: solo cuenta `end_date`, y
 * una relación sin fecha de fin se considera vigente. Es a propósito que sea la
 * misma y no "la correcta según yo": si el guardián contase con otra regla, el
 * número del árbol no cuadraría con la lista de marcado y nadie sabría cuál de
 * los dos mirar.
 */
export function estaVigente(rel, hoy = new Date()) {
  if (String(rel?.deleted ?? '0') === '1') return false;
  const fin = String(rel?.end_date ?? '').trim();
  if (fin === '') return true;
  const ts = Date.parse(`${fin}T23:59:59`);
  if (Number.isNaN(ts)) return true;   // fecha ilegible: no se descarta a nadie
  return ts >= hoy.getTime();
}

/** El nombre de la persona de una relación, tal como lo trae el CRM. */
export function nombreDe(rel) {
  return String(rel?.stic_contacts_relationships_contacts_name ?? '').trim();
}

/**
 * Reparte las relaciones de un grupo en participantes y monitores vigentes.
 *
 * Se cuentan PERSONAS y no relaciones: si alguien tiene dos relaciones vigentes
 * con el mismo grupo (pasa cuando se rehace una a mano), contarla dos veces
 * daría un grupo de 12 donde hay 11.
 */
export function clasificarRelaciones(relaciones, hoy = new Date()) {
  const participantes = new Map();
  const monitores = new Map();

  for (const rel of relaciones ?? []) {
    const papel = PAPELES[String(rel?.relationship_type ?? '')];
    if (!papel) continue;
    if (!estaVigente(rel, hoy)) continue;

    const nombre = nombreDe(rel);
    // Si no hay id de contacto, el nombre hace de clave: es lo único que queda
    // para no contar a la misma persona dos veces.
    const clave = String(rel?.stic_contacts_relationships_contactscontacts_ida ?? '').trim()
      || `nombre:${nombre.toLowerCase()}`;

    (papel === 'monitor' ? monitores : participantes).set(clave, nombre);
  }

  return {
    nParticipantes: participantes.size,
    nMonitores: monitores.size,
    nombresMonitores: [...monitores.values()].filter(Boolean),
  };
}

/**
 * Los nombres de los monitores para el campo de texto del grupo.
 *
 * Solo el nombre de pila: en una fila de móvil «David Soler Balado, Mercedes
 * Ramos Pérez» no cabe, y quien lee la pantalla ya sabe quién es «David». Se
 * ordenan para que el valor no cambie —y no se reescriba el registro— solo
 * porque el CRM haya devuelto las relaciones en otro orden.
 *
 * El separador es la coma, SIN «y» final: el «Juan, Antonio y María» lo monta la
 * pantalla, que sabe en qué idioma está y cuánto sitio tiene. Guardar la frase
 * ya montada es meter una decisión de presentación en la base de datos.
 */
export function formatearMonitores(nombres, { maxLargo = 255 } = {}) {
  const pilas = [...new Set(
    (nombres ?? [])
      .map((n) => String(n).trim().split(/\s+/)[0] ?? '')
      .filter(Boolean),
  )].sort((a, b) => a.localeCompare(b, 'es'));

  if (!pilas.length) return '';

  let texto = pilas.join(', ');
  if (texto.length <= maxLargo) return texto;

  // No cabe: se recorta por nombres enteros y se dice cuántos faltan, en vez de
  // cortar a media palabra.
  const salida = [];
  for (const p of pilas) {
    const restantes = pilas.length - salida.length - 1;
    const sufijo = restantes > 0 ? ` +${restantes}` : '';
    const prueba = [...salida, p].join(', ') + sufijo;
    if (prueba.length > maxLargo) break;
    salida.push(p);
  }
  if (!salida.length) return pilas[0].slice(0, maxLargo);
  const faltan = pilas.length - salida.length;
  return salida.join(', ') + (faltan > 0 ? ` +${faltan}` : '');
}

// ─────────────────────────────────────────────────────────────────────────────
// Escribir solo lo que cambia
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Qué campos hay que escribir de verdad.
 *
 * Escribir los 105 grupos cada noche llenaría el registro de auditoría del CRM
 * de cambios que no son cambios, y luego no se puede ver quién tocó un grupo de
 * verdad. Así que se compara con lo que ya hay y se devuelve solo la diferencia.
 *
 * `ajmcm_recuento_al_c` (el sello de cuándo se calculó) se añade SOLO si algún
 * otro campo cambia. Si se pusiera siempre, cada noche habría 105 escrituras y
 * volveríamos justo al problema que esto evita.
 */
export function camposQueCambian(grupo, calculado, { sello, campos }) {
  const cambios = {};

  const iguales = (a, b) => String(a ?? '').trim() === String(b ?? '').trim();
  const pares = [
    [campos.nParticipantes, calculado.nParticipantes],
    [campos.nMonitores, calculado.nMonitores],
    [campos.monitores, calculado.monitores],
  ];

  for (const [campo, valor] of pares) {
    if (!iguales(grupo[campo], valor)) cambios[campo] = valor;
  }

  if (Object.keys(cambios).length > 0 && sello) cambios[campos.recuentoAl] = sello;
  return cambios;
}

// ─────────────────────────────────────────────────────────────────────────────
// Revisión de datos
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Problemas de datos que merece la pena cantar cada noche.
 *
 * `recuentos` es lo ya calculado por la tarea de recuentos, así que esto no
 * cuesta ni una llamada más.
 */
export function revisarDatos(grupos, recuentos) {
  const sinCodigo = [];
  const sinMonitor = [];
  const sinNadie = [];

  for (const g of grupos ?? []) {
    const r = recuentos.get(g.id);
    const etiqueta = String(g.code ?? '').trim() || String(g.name ?? '').trim() || g.id;

    if (String(g.code ?? '').trim() === '') sinCodigo.push(etiqueta);
    if (r && r.nMonitores === 0 && r.nParticipantes > 0) sinMonitor.push(etiqueta);
    if (r && r.nMonitores === 0 && r.nParticipantes === 0) sinNadie.push(etiqueta);
  }

  return { sinCodigo, sinMonitor, sinNadie };
}
