/**
 * Tests del Guardián. Sin red: solo la lógica y el informe.
 *   node --test .github/scripts/guardian/
 *
 * Lo que se prueba es lo que puede hacer daño en silencio: contar mal, escribir
 * cuando no hace falta, ejecutarse a la hora que no toca, o no cantar un fallo.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  horaEnMadrid, tocaAhora, estaVigente, clasificarRelaciones,
  formatearMonitores, camposQueCambian, cursoDe, revisarDatos,
} from './logica.mjs';
import { aMarkdown, aTexto, hayFallos, mereceAviso, titular, estadoDe } from './informe.mjs';
import { CAMPOS } from './tareas/recuentos-grupos.mjs';

// ─────────────────────────────────────────────────────────────────────────────
// La hora
// ─────────────────────────────────────────────────────────────────────────────

test('la hora de Madrid tiene en cuenta el cambio de hora', () => {
  // Enero: CET (UTC+1). 00:30 UTC son las 01:30 de aquí.
  assert.deepEqual(horaEnMadrid(new Date('2026-01-15T00:30:00Z')), { hora: 1, minuto: 30 });
  // Julio: CEST (UTC+2). Las 01:30 de aquí son las 23:30 UTC del día anterior.
  assert.deepEqual(horaEnMadrid(new Date('2026-07-14T23:30:00Z')), { hora: 1, minuto: 30 });
});

test('de los dos crons solo pasa el que cae a la 1:30 de Madrid', () => {
  // Invierno: el bueno es 00:30 UTC; 23:30 UTC serían las 00:30 de aquí.
  assert.equal(tocaAhora({ horaObjetivo: 1, ahora: new Date('2026-01-15T00:30:00Z') }), true);
  // Verano: el bueno es 23:30 UTC; 00:30 UTC serían las 02:30 de aquí.
  assert.equal(tocaAhora({ horaObjetivo: 1, ahora: new Date('2026-07-14T23:30:00Z') }), true);
});

test('a mediodía no toca', () => {
  assert.equal(tocaAhora({ horaObjetivo: 1, ahora: new Date('2026-01-15T12:00:00Z') }), false);
});

test('aguanta el retraso de los crons de GitHub sin saltarse la noche', () => {
  // GitHub avisa de que los crons se retrasan en horas de carga. Con 40 minutos
  // de retraso la pasada TIENE que hacerse igual.
  assert.equal(tocaAhora({ horaObjetivo: 1, ahora: new Date('2026-01-15T01:10:00Z') }), true);
});

test('la ventana se mide de forma circular, no restando a pelo', () => {
  // Objetivo medianoche y son las 23:40: 20 minutos de distancia, no 1400.
  assert.equal(tocaAhora({ horaObjetivo: 0, ahora: new Date('2026-01-15T22:40:00Z'), margenMin: 30 }), true);
});

// ─────────────────────────────────────────────────────────────────────────────
// Vigencia y recuento
// ─────────────────────────────────────────────────────────────────────────────

const HOY = new Date('2026-08-23T02:00:00Z');

test('una relación sin fecha de fin sigue vigente', () => {
  assert.equal(estaVigente({ end_date: '' }, HOY), true);
  assert.equal(estaVigente({}, HOY), true);
});

test('una relación acabada el curso pasado no cuenta', () => {
  assert.equal(estaVigente({ end_date: '2025-06-30' }, HOY), false);
});

test('la relación que acaba hoy todavía cuenta', () => {
  // Acaba "el 23", así que el 23 la persona sigue en el grupo.
  assert.equal(estaVigente({ end_date: '2026-08-23' }, HOY), true);
});

test('una relación borrada no cuenta', () => {
  assert.equal(estaVigente({ deleted: '1', end_date: '' }, HOY), false);
});

test('una fecha ilegible no descarta a nadie', () => {
  // Ante la duda, la persona cuenta: es peor perder a alguien de la lista que
  // contar uno de más.
  assert.equal(estaVigente({ end_date: 'vete a saber' }, HOY), true);
});

test('separa participantes de monitores y coge los nombres', () => {
  const rels = [
    { relationship_type: 'participante_mic_com', end_date: '2026-08-31', stic_contacts_relationships_contactscontacts_ida: 'c1', stic_contacts_relationships_contacts_name: 'Solete Vilarroya' },
    { relationship_type: 'participante_mic_com', end_date: '', stic_contacts_relationships_contactscontacts_ida: 'c2', stic_contacts_relationships_contacts_name: 'Sol Meseguer' },
    { relationship_type: 'monitor', end_date: '2026-08-31', stic_contacts_relationships_contactscontacts_ida: 'm1', stic_contacts_relationships_contacts_name: 'David Soler Balado' },
    // De otro curso: fuera.
    { relationship_type: 'participante_mic_com', end_date: '2024-06-30', stic_contacts_relationships_contactscontacts_ida: 'c9', stic_contacts_relationships_contacts_name: 'Del Curso Pasado' },
    // Un tipo que no nos interesa: fuera.
    { relationship_type: 'familiar_menor', end_date: '', stic_contacts_relationships_contactscontacts_ida: 'f1', stic_contacts_relationships_contacts_name: 'Una Madre' },
  ];
  const r = clasificarRelaciones(rels, HOY);
  assert.equal(r.nParticipantes, 2);
  assert.equal(r.nMonitores, 1);
  assert.deepEqual(r.nombresMonitores, ['David Soler Balado']);
});

test('la misma persona con dos relaciones vigentes cuenta UNA vez', () => {
  // Pasa cuando alguien rehace una relación a mano sin cerrar la anterior. Sin
  // esto un grupo de 11 saldría de 12.
  const rels = [
    { relationship_type: 'participante_mic_com', end_date: '', stic_contacts_relationships_contactscontacts_ida: 'c1', stic_contacts_relationships_contacts_name: 'Solete' },
    { relationship_type: 'participante_mic_com', end_date: '2026-08-31', stic_contacts_relationships_contactscontacts_ida: 'c1', stic_contacts_relationships_contacts_name: 'Solete' },
  ];
  assert.equal(clasificarRelaciones(rels, HOY).nParticipantes, 1);
});

test('sin id de contacto, el nombre evita el duplicado', () => {
  const rels = [
    { relationship_type: 'monitor', end_date: '', stic_contacts_relationships_contacts_name: 'Marta' },
    { relationship_type: 'monitor', end_date: '', stic_contacts_relationships_contacts_name: 'marta' },
  ];
  assert.equal(clasificarRelaciones(rels, HOY).nMonitores, 1);
});

test('un grupo sin nadie da cero y no revienta', () => {
  const r = clasificarRelaciones([], HOY);
  assert.deepEqual(r, { nParticipantes: 0, nMonitores: 0, nombresMonitores: [] });
  assert.deepEqual(clasificarRelaciones(undefined, HOY).nParticipantes, 0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Los nombres de los monitores
// ─────────────────────────────────────────────────────────────────────────────

test('solo el nombre de pila, ordenado y sin repetir', () => {
  assert.equal(
    formatearMonitores(['David Soler Balado', 'Mercedes Ramos', 'Antonio Gil']),
    'Antonio, David, Mercedes',
  );
});

test('sin monitores, cadena vacía', () => {
  assert.equal(formatearMonitores([]), '');
  assert.equal(formatearMonitores(undefined), '');
});

test('el orden no depende de cómo los devuelva el CRM', () => {
  // Importa de verdad: si el valor cambia de orden, `camposQueCambian` lo ve
  // como un cambio y reescribiría el grupo cada noche.
  const a = formatearMonitores(['Ana Pérez', 'Bruno Díaz']);
  const b = formatearMonitores(['Bruno Díaz', 'Ana Pérez']);
  assert.equal(a, b);
});

test('si no cabe en el campo, se recorta por nombres enteros y se dice cuántos faltan', () => {
  const muchos = Array.from({ length: 60 }, (_, i) => `Nombrelargo${i} Apellido`);
  const salida = formatearMonitores(muchos, { maxLargo: 60 });
  assert.ok(salida.length <= 60, `se ha pasado de 60: ${salida.length}`);
  assert.match(salida, /\+\d+$/);
  assert.ok(!salida.includes('Nombrelargo1,,'));
});

// ─────────────────────────────────────────────────────────────────────────────
// Escribir solo lo que cambia
// ─────────────────────────────────────────────────────────────────────────────

const SELLO = '2026-08-23 01:30:00';

test('si nada ha cambiado, no se escribe NADA (ni el sello)', () => {
  const grupo = {
    [CAMPOS.nParticipantes]: '11',
    [CAMPOS.nMonitores]: '2',
    [CAMPOS.monitores]: 'David, Mercedes',
    [CAMPOS.recuentoAl]: '2026-08-20 01:30:00',
  };
  const cambios = camposQueCambian(grupo, { nParticipantes: 11, nMonitores: 2, monitores: 'David, Mercedes' },
    { sello: SELLO, campos: CAMPOS });
  assert.deepEqual(cambios, {});
});

test('si cambia el recuento, se escribe el recuento Y el sello', () => {
  const grupo = { [CAMPOS.nParticipantes]: '11', [CAMPOS.nMonitores]: '2', [CAMPOS.monitores]: 'David, Mercedes' };
  const cambios = camposQueCambian(grupo, { nParticipantes: 12, nMonitores: 2, monitores: 'David, Mercedes' },
    { sello: SELLO, campos: CAMPOS });
  assert.deepEqual(cambios, { [CAMPOS.nParticipantes]: 12, [CAMPOS.recuentoAl]: SELLO });
});

test('un grupo nuevo se rellena entero', () => {
  const cambios = camposQueCambian({}, { nParticipantes: 0, nMonitores: 0, monitores: '' },
    { sello: SELLO, campos: CAMPOS });
  // Con el grupo vacío, un 0 sí es un cambio respecto a "no hay nada puesto".
  assert.equal(cambios[CAMPOS.nParticipantes], 0);
  assert.equal(cambios[CAMPOS.recuentoAl], SELLO);
});

test('el CRM devuelve textos y nosotros números: eso NO es un cambio', () => {
  // Si esto falla, el guardián reescribe los 105 grupos cada noche.
  const grupo = { [CAMPOS.nParticipantes]: '11', [CAMPOS.nMonitores]: '0', [CAMPOS.monitores]: '' };
  const cambios = camposQueCambian(grupo, { nParticipantes: 11, nMonitores: 0, monitores: '' },
    { sello: SELLO, campos: CAMPOS });
  assert.deepEqual(cambios, {});
});

// ─────────────────────────────────────────────────────────────────────────────
// Revisión de datos
// ─────────────────────────────────────────────────────────────────────────────

test('el curso va de septiembre a agosto', () => {
  assert.equal(cursoDe(new Date('2026-08-23T12:00:00Z')), '2025-2026');
  assert.equal(cursoDe(new Date('2026-09-01T12:00:00Z')), '2026-2027');
});

test('detecta los grupos que Pasar Lista no ve por el filtro de cursos_c', () => {
  const grupos = [
    { id: 'g1', code: 'C1', cursos_c: '1º ESO' },        // invisible: no lleva "2025-2026"
    { id: 'g2', code: 'C2', cursos_c: '' },              // se ve (el filtro deja pasar los vacíos)
    { id: 'g3', code: 'C3', cursos_c: '2025-2026' },     // se ve
  ];
  const recuentos = new Map([['g1', { nParticipantes: 5, nMonitores: 1 }]]);
  const r = revisarDatos(grupos, recuentos, { curso: '2025-2026' });
  assert.deepEqual(r.invisiblesEnPasarLista, ['C1']);
});

test('avisa de grupos con chavales y sin monitor, y de los vacíos', () => {
  const grupos = [{ id: 'g1', code: 'C1' }, { id: 'g2', code: 'C2' }, { id: 'g3', name: 'Sin código' }];
  const recuentos = new Map([
    ['g1', { nParticipantes: 9, nMonitores: 0 }],
    ['g2', { nParticipantes: 0, nMonitores: 0 }],
    ['g3', { nParticipantes: 4, nMonitores: 1 }],
  ]);
  const r = revisarDatos(grupos, recuentos, { curso: '2025-2026' });
  assert.deepEqual(r.sinMonitor, ['C1']);
  assert.deepEqual(r.sinNadie, ['C2']);
  assert.deepEqual(r.sinCodigo, ['Sin código']);
});

// ─────────────────────────────────────────────────────────────────────────────
// El informe: que CANTE
// ─────────────────────────────────────────────────────────────────────────────

const CTX = { cuando: '23 ago 2026, 1:30', llamadas: 120, duracionSeg: 42, secoDePrueba: false, avisosCrm: [] };

test('un fallo se ve en el titular, en la tabla y en el detalle', () => {
  const resultados = [
    { clave: 'a', titulo: 'Tarea A', resumen: '10 revisados', detalles: [], problemas: [] },
    { clave: 'b', titulo: 'Tarea B', error: { mensaje: 'Faltan campos en ajmcm_GRUPOS', pila: 'en algún sitio' } },
  ];
  assert.equal(hayFallos(resultados), true);
  assert.equal(titular(resultados), '1 tarea ha fallado');

  const md = aMarkdown({ resultados, contexto: CTX });
  assert.match(md, /❌/);
  assert.match(md, /Faltan campos en ajmcm_GRUPOS/);
  assert.match(md, /Tarea B/);
});

test('sin fallos ni avisos, no se manda correo', () => {
  const resultados = [{ clave: 'a', titulo: 'A', resumen: 'nada', detalles: [], problemas: [] }];
  assert.equal(mereceAviso(resultados), false);
  assert.equal(hayFallos(resultados), false);
  assert.equal(titular(resultados), 'todo bien · sin cambios');
});

test('un aviso sin fallo también merece correo, pero el job no sale rojo', () => {
  const resultados = [{ clave: 'a', titulo: 'A', resumen: 'ojo', problemas: ['12 grupos invisibles'] }];
  assert.equal(mereceAviso(resultados), true);
  assert.equal(hayFallos(resultados), false);
  assert.equal(estadoDe(resultados[0]), 'aviso');
});

test('una barra en el texto no parte la tabla del resumen', () => {
  const resultados = [{ clave: 'a', titulo: 'A', resumen: 'esto | aquello', detalles: [], problemas: [] }];
  const md = aMarkdown({ resultados, contexto: CTX });
  assert.match(md, /esto \\\| aquello/);
});

test('la prueba en seco se dice en el informe', () => {
  const resultados = [{ clave: 'a', titulo: 'A', resumen: 'x', detalles: ['uno'], problemas: [] }];
  const md = aMarkdown({ resultados, contexto: { ...CTX, secoDePrueba: true } });
  assert.match(md, /prueba en seco/);
});

test('el texto del correo lleva el enlace a la ejecución', () => {
  const resultados = [{ clave: 'a', titulo: 'A', error: { mensaje: 'roto' } }];
  const txt = aTexto({ resultados, contexto: { ...CTX, enlaceEjecucion: 'https://github.com/x/y/actions/runs/1' } });
  assert.match(txt, /actions\/runs\/1/);
  assert.match(txt, /roto/);
});
