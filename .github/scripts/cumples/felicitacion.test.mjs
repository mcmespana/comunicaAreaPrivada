/**
 * Pruebas de la lógica de cumpleaños. Sin red y sin dependencias:
 *
 *     node --test .github/scripts/cumples/
 *
 * Los registros de ejemplo están copiados tal cual de SinergiaCRM (incluidos
 * los booleanos que llegan como "1" y los duplicados de la misma persona), que
 * es justo donde es fácil equivocarse.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
  asunto, asuntoPersona, correoHtml, correoPersonaHtml, cumpleanosDeHoy,
  delegacionesActivas, edad, elegir, fechaLarga, indiceMonitores, listaNatural,
  MENSAJES_POR_DEFECTO, movilInternacional, normaliza, palabraMonitor,
  plantilla, relacionEsDe, repartoGifs, textoPersona, textoPlano, urlFicha,
} from './felicitacion.mjs';

// Los textos de verdad, los mismos que usa el workflow.
const MENSAJES = JSON.parse(
  readFileSync(new URL('./mensajes.json', import.meta.url), 'utf8'),
);

const CASTELLON = {
  clave: 'castellon',
  activa: true,
  nombre: 'MCM Castellón',
  usuario_crm: 'MCM Castellón',
  destinatarios: ['Castellon@movimientoconsolacion.com'],
};

// Muestra real: dos monitoras de Castellón (una repetida, como en el CRM), una
// relación de otra delegación, una de otro tipo y una dada de baja.
const RELACIONES = [
  {
    id: 'r1',
    relationship_type: 'monitor',
    active: '1',
    start_date: '2022-09-01',
    assigned_user_name: 'MCM Castellón',
    ajmcm_delegacion_c: 'castellon',
    stic_contacts_relationships_contactscontacts_ida: 'c-mercedes',
  },
  {
    id: 'r2',
    relationship_type: 'monitor',
    active: '1',
    start_date: '2019-09-01', // más antigua: es la que debe ganar
    assigned_user_name: 'MCM Castellón',
    ajmcm_delegacion_c: 'castellon',
    stic_contacts_relationships_contactscontacts_ida: 'c-mercedes',
  },
  {
    id: 'r3',
    relationship_type: 'monitor',
    active: '1',
    start_date: '2020-09-01',
    assigned_user_name: 'MCM Villacañas',
    ajmcm_delegacion_c: 'villacanas',
    stic_contacts_relationships_contactscontacts_ida: 'c-forastero',
  },
  {
    id: 'r4',
    relationship_type: 'familiar_menor', // no es monitor/a
    active: '1',
    assigned_user_name: 'MCM Castellón',
    ajmcm_delegacion_c: 'castellon',
    stic_contacts_relationships_contactscontacts_ida: 'c-familiar',
  },
  {
    id: 'r5',
    relationship_type: 'monitor',
    active: '0', // ya no está activa
    assigned_user_name: 'MCM Castellón',
    ajmcm_delegacion_c: 'castellon',
    stic_contacts_relationships_contactscontacts_ida: 'c-exmonitor',
  },
];

const CONTACTOS = [
  {
    id: 'c-mercedes',
    first_name: 'Mercedes',
    last_name: 'Martí París',
    birthdate: '2006-08-18',
    stic_gender_c: 'female',
    phone_mobile: '626406615',
    email1: 'mercedes@example.org',
    ajmcm_etapa_c: 'COM',
  },
  { id: 'c-forastero', first_name: 'Ana', last_name: 'De Otra Parte', birthdate: '2000-08-18' },
  { id: 'c-familiar', first_name: 'Sol', last_name: 'Meseguer', birthdate: '2006-08-18' },
  { id: 'c-exmonitor', first_name: 'Ex', last_name: 'Monitor', birthdate: '2006-08-18' },
  // Es de Castellón, pero cumple otro día: el filtro del CRM es un LIKE y
  // conviene comprobar que aquí se vuelve a verificar el día.
  { id: 'c-mercedes-otro-dia', first_name: 'Otro', last_name: 'Día', birthdate: '2006-03-18' },
];

const GIFS = Array.from({ length: 5 }, (_, i) => ({
  url: `https://ejemplo/gif-${i}.gif`, ancho: 200, alto: 150, alt: `gif ${i}`,
}));

// ── Fechas y textos ──────────────────────────────────────────────────────────

test('edad: cuenta años y descarta fechas centinela', () => {
  assert.equal(edad('2006-08-18', '2026-08-18'), 20);
  assert.equal(edad('1998-01-01', '2026-08-18'), 28);
  assert.equal(edad('1899-01-01', '2026-01-01'), null, 'anterior a 1900');
  assert.equal(edad('2030-01-01', '2026-01-01'), null, 'nacido en el futuro');
  assert.equal(edad('', '2026-01-01'), null);
  assert.equal(edad(null, '2026-01-01'), null);
  assert.equal(edad('18/08/2006', '2026-01-01'), null, 'formato que no es ISO');
});

test('fechaLarga: castellano, sin cero delante y sin depender del locale', () => {
  assert.equal(fechaLarga('2026-08-20'), '20 de agosto de 2026');
  assert.equal(fechaLarga('2006-01-05'), '5 de enero de 2006');
  assert.equal(fechaLarga('vaya'), 'vaya');
});

test('listaNatural: une con comas y una "y" al final', () => {
  assert.equal(listaNatural([]), '');
  assert.equal(listaNatural(['Ana']), 'Ana');
  assert.equal(listaNatural(['Ana', 'Luis']), 'Ana y Luis');
  assert.equal(listaNatural(['Ana', 'Luis', 'Marta']), 'Ana, Luis y Marta');
});

test('palabraMonitor: usa el género del CRM y si no lo hay, neutro', () => {
  assert.equal(palabraMonitor('female'), 'monitora');
  assert.equal(palabraMonitor('male'), 'monitor');
  assert.equal(palabraMonitor(''), 'monitor/a');
  assert.equal(palabraMonitor(undefined), 'monitor/a');
});

test('movilInternacional: solo móviles, con prefijo 34 si falta', () => {
  assert.equal(movilInternacional('626406615'), '34626406615');
  assert.equal(movilInternacional('626 40 66 15'), '34626406615');
  assert.equal(movilInternacional('+34 626406615'), '34626406615');
  assert.equal(movilInternacional('700000000'), '34700000000');
  assert.equal(movilInternacional('964123456'), '', 'un fijo no lleva WhatsApp');
  assert.equal(movilInternacional(''), '');
  assert.equal(movilInternacional('no tiene'), '');
});

// ── Mensajes (mensajes.json) ─────────────────────────────────────────────────

test('mensajes.json: tiene todas las listas y ninguna vacía', () => {
  for (const clave of Object.keys(MENSAJES_POR_DEFECTO)) {
    assert.ok(Array.isArray(MENSAJES[clave]), `falta la lista "${clave}"`);
    assert.ok(MENSAJES[clave].length > 0, `"${clave}" está vacía`);
    for (const t of MENSAJES[clave]) {
      assert.equal(typeof t, 'string');
      assert.ok(t.trim().length > 0, `hay un texto vacío en "${clave}"`);
    }
  }
});

test('mensajes.json: titulares y asuntos llevan el hueco {nombre}', () => {
  for (const clave of ['titulares', 'asuntos']) {
    for (const t of MENSAJES[clave]) {
      assert.ok(t.includes('{nombre}'), `"${t}" no tiene {nombre}`);
    }
  }
  // En el resto no debe haber huecos: nadie los sustituiría.
  for (const clave of ['agradecimientos', 'frases']) {
    for (const t of MENSAJES[clave]) {
      assert.ok(!t.includes('{'), `"${t}" tiene un hueco que no se rellena`);
    }
  }
});

test('elegir: estable, y reparte entre toda la lista', () => {
  assert.equal(
    elegir(MENSAJES, 'frases', '2026-08-20|abc'),
    elegir(MENSAJES, 'frases', '2026-08-20|abc'),
  );
  // Con 300 semillas deberían salir bastantes frases distintas; si el hash
  // estuviera sesgado se vería aquí.
  const vistas = new Set(
    Array.from({ length: 300 }, (_, i) => elegir(MENSAJES, 'frases', `sem-${i}`)),
  );
  assert.ok(vistas.size >= MENSAJES.frases.length * 0.8,
    `solo salen ${vistas.size} de ${MENSAJES.frases.length} frases`);
});

test('elegir: si la lista falta o está vacía, tira de la de reserva', () => {
  assert.equal(elegir({}, 'frases', 'x'), MENSAJES_POR_DEFECTO.frases[0]);
  assert.equal(elegir({ frases: [] }, 'frases', 'x'), MENSAJES_POR_DEFECTO.frases[0]);
  assert.equal(elegir(null, 'frases', 'x'), MENSAJES_POR_DEFECTO.frases[0]);
});

test('plantilla: sustituye todos los {nombre}', () => {
  assert.equal(plantilla('¡Hola {nombre}, {nombre}!', 'Ana'), '¡Hola Ana, Ana!');
  assert.equal(plantilla('sin hueco', 'Ana'), 'sin hueco');
});

// ── Delegaciones (el FLAG) ───────────────────────────────────────────────────

test('delegacionesActivas: por defecto solo las marcadas como activas', () => {
  const config = {
    delegaciones: [
      CASTELLON,
      { clave: 'onda', activa: false, nombre: 'MCM Onda', destinatarios: ['onda@x.org'] },
    ],
  };
  assert.deepEqual(delegacionesActivas(config).map((d) => d.clave), ['castellon']);
});

test('delegacionesActivas: "todas" ignora el flag, pero no la falta de destinatarios', () => {
  const config = {
    delegaciones: [
      CASTELLON,
      { clave: 'onda', activa: false, nombre: 'MCM Onda', destinatarios: ['onda@x.org'] },
      { clave: 'reus', activa: true, nombre: 'MCM Reus', destinatarios: [] },
    ],
  };
  assert.deepEqual(delegacionesActivas(config, 'todas').map((d) => d.clave), ['castellon', 'onda']);
});

test('delegacionesActivas: una lista explícita manda sobre el flag', () => {
  const config = {
    delegaciones: [
      CASTELLON,
      { clave: 'onda', activa: false, nombre: 'MCM Onda', destinatarios: ['onda@x.org'] },
    ],
  };
  assert.deepEqual(delegacionesActivas(config, 'onda').map((d) => d.clave), ['onda']);
  assert.deepEqual(delegacionesActivas(config, ' ONDA , castellon ').map((d) => d.clave),
    ['castellon', 'onda']);
});

test('delegacionesActivas: aguanta configuraciones vacías o rotas', () => {
  assert.deepEqual(delegacionesActivas({}), []);
  assert.deepEqual(delegacionesActivas({ delegaciones: [{ activa: true }] }), []);
});

// ── Cruce de datos ───────────────────────────────────────────────────────────

test('normaliza: quita tildes, mayúsculas y guiones', () => {
  assert.equal(normaliza('MCM Vila-real'), 'mcmvilareal');
  assert.equal(normaliza('MCM Castellón'), normaliza('mcm castellon'));
});

test('relacionEsDe: vale con la clave o con el usuario asignado', () => {
  assert.ok(relacionEsDe({ ajmcm_delegacion_c: 'castellon' }, CASTELLON));
  assert.ok(relacionEsDe({ assigned_user_name: 'MCM Castellon' }, CASTELLON), 'sin tilde');
  // Caso real: la clave del CRM lleva errata y solo cuadra el usuario.
  assert.ok(relacionEsDe(
    { ajmcm_delegacion_c: 'madird', assigned_user_name: 'MCM Madrid' },
    { clave: 'madrid', usuario_crm: 'MCM Madrid' },
  ));
  assert.ok(!relacionEsDe({ ajmcm_delegacion_c: 'onda', assigned_user_name: 'MCM Onda' }, CASTELLON));
  assert.ok(!relacionEsDe({}, CASTELLON), 'sin datos no se cuela nadie');
});

test('indiceMonitores: solo monitor/a activo/a, deduplicado y con la fecha más antigua', () => {
  const indice = indiceMonitores(RELACIONES, CASTELLON);
  assert.deepEqual([...indice.keys()], ['c-mercedes']);
  assert.equal(indice.get('c-mercedes').desde, '2019-09-01');
});

test('indiceMonitores: acepta el booleano como 1, "1" o true', () => {
  const base = {
    relationship_type: 'monitor',
    ajmcm_delegacion_c: 'castellon',
    stic_contacts_relationships_contactscontacts_ida: 'c1',
  };
  for (const active of ['1', 1, true]) {
    assert.equal(indiceMonitores([{ ...base, active }], CASTELLON).size, 1, `active=${active}`);
  }
  for (const active of ['0', 0, false, null, undefined]) {
    assert.equal(indiceMonitores([{ ...base, active }], CASTELLON).size, 0, `active=${active}`);
  }
});

test('cumpleanosDeHoy: cruza, filtra por día y ordena', () => {
  const cumples = cumpleanosDeHoy(CONTACTOS, indiceMonitores(RELACIONES, CASTELLON), '2026-08-18');
  assert.equal(cumples.length, 1);
  assert.equal(cumples[0].nombre, 'Mercedes Martí París');
  assert.equal(cumples[0].edad, 20);
  assert.equal(cumples[0].monitorDesde, '2019-09-01');
  assert.equal(cumples[0].nombreCorto, 'Mercedes');
});

test('cumpleanosDeHoy: si no es el día de nadie, no devuelve nada', () => {
  const indice = indiceMonitores(RELACIONES, CASTELLON);
  assert.deepEqual(cumpleanosDeHoy(CONTACTOS, indice, '2026-11-11'), []);
});

test('cumpleanosDeHoy: ordena por nombre en castellano', () => {
  const indice = new Map([['a', { desde: null }], ['b', { desde: null }], ['c', { desde: null }]]);
  const contactos = [
    { id: 'a', first_name: 'Zoe', last_name: '', birthdate: '2000-05-05' },
    { id: 'b', first_name: 'Ángel', last_name: '', birthdate: '2000-05-05' },
    { id: 'c', first_name: 'Ana', last_name: '', birthdate: '2000-05-05' },
  ];
  assert.deepEqual(
    cumpleanosDeHoy(contactos, indice, '2026-05-05').map((c) => c.nombre),
    ['Ana', 'Ángel', 'Zoe'],
  );
});

// ── GIFs ─────────────────────────────────────────────────────────────────────

test('repartoGifs: determinista, sin repetir y sin romperse si la bolsa es corta', () => {
  const a = repartoGifs(GIFS, '2026-08-20|castellon', 3);
  assert.deepEqual(a, repartoGifs(GIFS, '2026-08-20|castellon', 3), 'misma semilla, mismo reparto');
  assert.equal(new Set(a.map((g) => g.url)).size, 3, 'sin repetidos');
  assert.notDeepEqual(a, repartoGifs(GIFS, '2026-08-21|castellon', 3), 'otro día, otros GIFs');
  assert.equal(repartoGifs(GIFS, 'x', 7).length, 7, 'si piden más que hay, se repiten');
  assert.deepEqual(repartoGifs([], 'x', 3), []);
  assert.deepEqual(repartoGifs(GIFS, 'x', 0), []);
});

// ── Correo ───────────────────────────────────────────────────────────────────

test('asunto: en singular lleva nombre y edad; en plural, cuántos son', () => {
  const uno = [{ nombre: 'Mercedes Martí París', nombreCorto: 'Mercedes', edad: 20 }];
  assert.equal(asunto(uno, 'MCM Castellón'),
    '🎂 Hoy es el cumple de Mercedes Martí París (20) — MCM Castellón');

  const dos = [...uno, { nombre: 'Jaime Pardo', nombreCorto: 'Jaime', edad: 28 }];
  assert.equal(asunto(dos, 'MCM Castellón'),
    '🎂 Hoy cumplen años 2: Mercedes y Jaime — MCM Castellón');
});

test('asunto: sin edad no se inventa nada', () => {
  const uno = [{ nombre: 'Sin Fecha', nombreCorto: 'Sin', edad: null }];
  assert.equal(asunto(uno, 'MCM Onda'), '🎂 Hoy es el cumple de Sin Fecha — MCM Onda');
});

test('urlFicha: apunta a la ficha del CRM con el id escapado', () => {
  assert.match(urlFicha('abc-123'), /module=Contacts&action=DetailView&record=abc-123$/);
});

test('textoPlano: lleva nombre, edad, contacto y enlace a la ficha', () => {
  const cumples = cumpleanosDeHoy(CONTACTOS, indiceMonitores(RELACIONES, CASTELLON), '2026-08-18');
  const texto = textoPlano(cumples, CASTELLON, '2026-08-18');
  assert.match(texto, /Mercedes Martí París cumple 20 años \(monitora\)/);
  assert.match(texto, /Nació el 18 de agosto de 2006/);
  assert.match(texto, /Monitora desde 2019/);
  assert.match(texto, /626406615/);
  assert.match(texto, /module=Contacts/);
});

test('correoHtml: monta el correo con GIF, datos y botones', () => {
  const cumples = cumpleanosDeHoy(CONTACTOS, indiceMonitores(RELACIONES, CASTELLON), '2026-08-18');
  const html = correoHtml(cumples, CASTELLON, '2026-08-18', GIFS);

  assert.match(html, /<!DOCTYPE html/);
  assert.match(html, /Mercedes Martí París/);
  assert.match(html, /20 años/);
  assert.match(html, /MCM Castellón/);
  assert.match(html, /wa\.me\/34626406615/, 'botón de WhatsApp');
  assert.match(html, /mailto:mercedes@example\.org/);
  // Cabecera + una tarjeta = dos GIFs distintos.
  const usados = [...html.matchAll(/https:\/\/ejemplo\/gif-\d\.gif/g)].map((m) => m[0]);
  assert.equal(new Set(usados).size, 2);
  // El alto se recalcula a partir del ancho mostrado (200x150 -> 180x135).
  assert.match(html, /width="180" height="135"/);
});

test('correoHtml: sin móvil no aparece el botón de WhatsApp', () => {
  const cumples = [{
    id: 'x', nombre: 'Sin Móvil', nombreCorto: 'Sin', nacimiento: '2000-08-18',
    edad: 26, genero: 'male', movil: '', email: '', etapa: '', grupo: '', monitorDesde: null,
  }];
  const html = correoHtml(cumples, CASTELLON, '2026-08-18', GIFS);
  assert.ok(!html.includes('wa.me'));
  assert.ok(!html.includes('mailto:'));
  assert.match(html, /👤 Ficha/, 'el enlace a la ficha siempre está');
});

test('correoHtml: escapa el HTML que venga del CRM', () => {
  const cumples = [{
    id: 'x', nombre: '<script>ups</script>', nombreCorto: 'ups', nacimiento: '2000-08-18',
    edad: 26, genero: '', movil: '', email: '', etapa: '', grupo: '', monitorDesde: null,
  }];
  const html = correoHtml(cumples, CASTELLON, '2026-08-18', GIFS);
  assert.ok(!html.includes('<script>ups'));
  assert.match(html, /&lt;script&gt;ups/);
});

test('correoHtml: aguanta que no haya GIFs', () => {
  const cumples = cumpleanosDeHoy(CONTACTOS, indiceMonitores(RELACIONES, CASTELLON), '2026-08-18');
  const html = correoHtml(cumples, CASTELLON, '2026-08-18', []);
  assert.match(html, /Mercedes Martí París/);
  assert.ok(!html.includes('<img'));
});

// ── Correo directo a la persona que cumple ───────────────────────────────────

const MERCEDES = cumpleanosDeHoy(
  CONTACTOS, indiceMonitores(RELACIONES, CASTELLON), '2026-08-18',
)[0];

test('asuntoPersona: sale de la lista y lleva su nombre de pila', () => {
  const a = asuntoPersona(MERCEDES, '2026-08-18', MENSAJES);
  assert.match(a, /Mercedes/);
  assert.ok(!a.includes('{nombre}'), 'el hueco se ha rellenado');
  assert.ok(MENSAJES.asuntos.some((t) => plantilla(t, 'Mercedes') === a));
});

test('los textos varían entre personas del mismo día', () => {
  const otro = { ...MERCEDES, id: 'otro-id', nombreCorto: 'Mercedes' };
  const distintos = ['asuntos', 'agradecimientos', 'frases', 'titulares'].filter(
    (clave) => elegir(MENSAJES, clave, `2026-08-18|${MERCEDES.id}`)
      !== elegir(MENSAJES, clave, `2026-08-18|${otro.id}`),
  );
  assert.ok(distintos.length >= 3,
    `solo cambian ${distintos.length} listas de 4 entre dos personas`);
});

test('correoPersonaHtml: felicita sin soltarle sus propios datos', () => {
  const html = correoPersonaHtml(MERCEDES, CASTELLON, '2026-08-18', GIFS, MENSAJES);
  assert.match(html, /Mercedes/);
  assert.ok(!html.includes('{nombre}'), 'sin huecos sin rellenar');
  assert.match(html, /20 años/);
  assert.match(html, /MCM Castellón/);
  // Lo importante: en la felicitación NO va información interna.
  assert.ok(!html.includes('626406615'), 'sin su teléfono');
  assert.ok(!html.includes('module=Contacts'), 'sin enlace al CRM');
  assert.ok(!html.includes('wa.me'), 'sin botones de gestión');
  assert.match(html, /monitora de MCM Castellón/, 'el pie explica por qué le llega');
});

test('correoPersonaHtml: le toca un GIF distinto del del aviso a la delegación', () => {
  const suyo = correoPersonaHtml(MERCEDES, CASTELLON, '2026-08-18', GIFS, MENSAJES);
  const aviso = correoHtml([MERCEDES], CASTELLON, '2026-08-18', GIFS);
  const usados = (h) => [...h.matchAll(/https:\/\/ejemplo\/gif-(\d)\.gif/g)].map((m) => m[1]);
  assert.equal(usados(suyo).length, 1);
  assert.ok(!usados(aviso).includes(usados(suyo)[0]), 'no repite el de la delegación');
});

test('correoPersonaHtml: es estable dentro del mismo día', () => {
  assert.equal(
    correoPersonaHtml(MERCEDES, CASTELLON, '2026-08-18', GIFS, MENSAJES),
    correoPersonaHtml(MERCEDES, CASTELLON, '2026-08-18', GIFS, MENSAJES),
  );
  assert.notEqual(
    correoPersonaHtml(MERCEDES, CASTELLON, '2026-08-18', GIFS, MENSAJES),
    correoPersonaHtml(MERCEDES, CASTELLON, '2027-08-18', GIFS, MENSAJES),
  );
});

test('correoPersonaHtml y textoPersona: sin edad no se inventan un número', () => {
  const sinEdad = { ...MERCEDES, edad: null };
  assert.match(correoPersonaHtml(sinEdad, CASTELLON, '2026-08-18', GIFS, MENSAJES), /Hoy es tu cumpleaños/);
  assert.match(textoPersona(sinEdad, CASTELLON, '2026-08-18', MENSAJES), /Hoy cumples y desde/);
});

test('textoPersona: lleva firma y agradecimiento', () => {
  const texto = textoPersona(MERCEDES, CASTELLON, '2026-08-18', MENSAJES);
  assert.match(texto, /Mercedes/);
  assert.match(texto, /MCM Castellón/);
  assert.match(texto, /Gracias/);
});
