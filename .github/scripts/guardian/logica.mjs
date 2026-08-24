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
// Los dos modos: suave y completo
// ─────────────────────────────────────────────────────────────────────────────

/**
 * `full`  — recalcula TODOS los grupos. Una llamada por grupo, y es el único
 *           modo que se entera de una relación **borrada**: el API excluye los
 *           borrados de cualquier consulta, así que si una relación desaparece
 *           no hay forma de saber a qué grupo pertenecía. Solo recontando el
 *           grupo entero sale el número bueno.
 * `soft`   — recalcula solo los grupos con alguna relación tocada hace poco.
 *           Dos o tres llamadas en vez de cien. Rápido, pero ciego a los
 *           borrados (ver arriba) y a una vigencia que caduca sola: un
 *           `end_date` que llega no modifica el registro.
 *
 * Por eso el suave NO puede ir solo: el completo del viernes y del sábado es la
 * red que recoge lo que el suave no puede ver.
 */
export const MODOS = ['soft', 'full'];

/** Los días (0 = domingo) en los que toca pasada completa: viernes y sábado. */
export const DIAS_COMPLETOS = [5, 6];

const DIA_A_NUMERO = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };

/**
 * Día de la semana en Madrid (0 = domingo).
 *
 * Se mira la zona de aquí y no la del runner por lo mismo que la hora: a la 1:30
 * de un viernes de verano, en UTC son las 23:30 del JUEVES. Con el día del
 * runner, la pasada completa del viernes caería en jueves media parte del año.
 */
export function diaEnMadrid(fecha = new Date()) {
  const corto = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Europe/Madrid',
    weekday: 'short',
  }).format(fecha);
  return DIA_A_NUMERO[corto] ?? -1;
}

/** Qué modo toca esta noche: completo el viernes y el sábado, suave el resto. */
export function modoDeLaNoche(fecha = new Date()) {
  return DIAS_COMPLETOS.includes(diaEnMadrid(fecha)) ? 'full' : 'soft';
}

/**
 * Desde qué día mira el modo suave, en `YYYY-MM-DD`.
 *
 * La ventana es de varios días y no de 24 horas a propósito: así una noche que
 * falle (o que se retrase) no abre un agujero — la siguiente pasada vuelve a
 * cubrir ese día. Se usa día entero y no hora exacta porque el filtro con fecha
 * suelta es el que está comprobado contra el CRM, y porque redondear hacia atrás
 * solo ensancha la ventana, que es el lado seguro de equivocarse.
 */
export function ventanaDesde(hoy = new Date(), dias = 3) {
  const atras = new Date(hoy.getTime() - Math.max(1, dias) * 86_400_000);
  return atras.toISOString().slice(0, 10);
}

/**
 * El campo de la relación que lleva el **id** del grupo.
 *
 * Comprobado contra el CRM (24/08): en una consulta al módulo viene relleno con
 * el id de verdad, no con el nombre. Eso es lo que hace viable el modo suave —
 * el atajo que `PASAR-LISTA-RECUENTOS.md` §2 descarta era emparejar por NOMBRE,
 * que sí se rompe con dos grupos que se llaman igual.
 */
export const CAMPO_GRUPO_EN_RELACION = 'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida';

/**
 * De un montón de relaciones, los ids de los grupos que hay que recalcular.
 *
 * Las relaciones sin grupo se cuentan aparte y no son un problema: el
 * acompañamiento de monitores es una relación sin grupo por diseño.
 */
export function gruposTocados(relaciones, campo = CAMPO_GRUPO_EN_RELACION) {
  const ids = new Set();
  let sinGrupo = 0;

  for (const rel of relaciones ?? []) {
    const id = String(rel?.[campo] ?? '').trim();
    if (id === '') {
      sinGrupo += 1;
      continue;
    }
    ids.add(id);
  }

  return { ids, sinGrupo };
}

// ─────────────────────────────────────────────────────────────────────────────
// Relaciones persona ↔ grupo
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Los `relationship_type` que nos interesan, con el papel que juegan.
 *
 * `grupo` es el papel de los +18 en su grupo de referencia: no llevan
 * "participante_mic_com" pero cuentan igual como participantes del grupo.
 * MISMO mapa que `sticpa_pl_rel_types()` en PHP — si un rol cambia, cambia
 * en los dos sitios o el número del área privada y el del Guardián dejan de
 * cuadrar.
 */
export const PAPELES = {
  participante_mic_com: 'participante',
  grupo: 'participante',
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
 *
 * En modo suave solo se ha recalculado un puñado de grupos, así que para los
 * demás se usa **el número que ya está escrito en el grupo** (que lo dejó la
 * última pasada). Así el suave revisa los 105 igual que el completo, en vez de
 * callarse sobre 102 de ellos — que sería peor que no revisar: un informe que
 * dice "nada que revisar" cuando en realidad no ha mirado.
 *
 * Si no hay ni recuento fresco ni número guardado, de ese grupo **no se opina**.
 * Un hueco se entiende; inventarse un cero y avisar de un grupo vacío que no lo
 * está, no.
 */
export function revisarDatos(grupos, recuentos, campos = null) {
  const sinCodigo = [];
  const sinMonitor = [];
  const sinNadie = [];

  for (const g of grupos ?? []) {
    const etiqueta = String(g.code ?? '').trim() || String(g.name ?? '').trim() || g.id;
    if (String(g.code ?? '').trim() === '') sinCodigo.push(etiqueta);

    const fresco = recuentos?.get(g.id);
    const nParticipantes = fresco ? fresco.nParticipantes : numeroGuardado(g, campos?.nParticipantes);
    const nMonitores = fresco ? fresco.nMonitores : numeroGuardado(g, campos?.nMonitores);
    if (nParticipantes === null || nMonitores === null) continue;

    if (nMonitores === 0 && nParticipantes > 0) sinMonitor.push(etiqueta);
    if (nMonitores === 0 && nParticipantes === 0) sinNadie.push(etiqueta);
  }

  return { sinCodigo, sinMonitor, sinNadie };
}

/** Un entero guardado en el grupo, o `null` si no hay nada que leer. */
function numeroGuardado(grupo, campo) {
  if (!campo) return null;
  const bruto = String(grupo?.[campo] ?? '').trim();
  if (bruto === '') return null;
  const n = Number(bruto);
  return Number.isFinite(n) ? n : null;
}
