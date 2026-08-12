<?php

use PHPUnit\Framework\TestCase;

/**
 * sticpa_event_ids_from_calendar_cache(): la pieza que permite al guard
 * anti-duplicado de inscripciones (prefix_user_active_event_ids) responder
 * SIN llamar al CRM cuando el calendario ya tiene su caché caliente.
 *
 * Por qué importa que esté cubierta: si esta función devolviera una lista
 * incompleta, el guard dejaría crear una inscripción DUPLICADA; si devolviera
 * un array (en vez de null) cuando no hay caché, el guard creería que el
 * usuario no está inscrito en nada. La distinción null / array-vacío es la
 * propiedad crítica, y es justo la que se comprueba aquí.
 */
final class CalendarCacheTest extends TestCase
{
    /** Sin caché (get_transient devuelve false) → hay que preguntar al CRM. */
    public function test_sin_cache_devuelve_null()
    {
        $this->assertNull(sticpa_event_ids_from_calendar_cache(false));
    }

    /** Caché con forma inesperada → null, nunca una lista a medias. */
    public function test_forma_inesperada_devuelve_null()
    {
        $this->assertNull(sticpa_event_ids_from_calendar_cache(null));
        $this->assertNull(sticpa_event_ids_from_calendar_cache('cadena'));
        $this->assertNull(sticpa_event_ids_from_calendar_cache(array()));
        $this->assertNull(sticpa_event_ids_from_calendar_cache(array('sessions' => array())));
        $this->assertNull(sticpa_event_ids_from_calendar_cache(array('registered_events' => 'no-es-array')));
    }

    /**
     * Caché válida sin inscripciones → array VACÍO, que NO es lo mismo que null:
     * significa "he mirado y no está inscrito en nada", así que el guard puede
     * ahorrarse la llamada al CRM.
     */
    public function test_cache_valida_sin_inscripciones_devuelve_array_vacio()
    {
        $ids = sticpa_event_ids_from_calendar_cache(array('registered_events' => array()));
        $this->assertIsArray($ids);
        $this->assertSame(array(), $ids);
    }

    /** Caso normal: se extraen los ids en orden. */
    public function test_extrae_los_ids_de_los_eventos_inscritos()
    {
        $cached = array(
            'registered_events' => array(
                array('id' => 'evento-1', 'name' => 'Campamento', 'start' => '2026-07-01', 'end' => '2026-07-10'),
                array('id' => 'evento-2', 'name' => 'Convivencia', 'start' => '2026-09-01', 'end' => '2026-09-02'),
            ),
            'sessions' => array(),
        );
        $this->assertSame(array('evento-1', 'evento-2'), sticpa_event_ids_from_calendar_cache($cached));
    }

    /** Ids repetidos (varias inscripciones al mismo evento) → una sola vez. */
    public function test_deduplica_ids_repetidos()
    {
        $cached = array('registered_events' => array(
            array('id' => 'evento-1'),
            array('id' => 'evento-1'),
            array('id' => 'evento-2'),
        ));
        $this->assertSame(array('evento-1', 'evento-2'), sticpa_event_ids_from_calendar_cache($cached));
    }

    /** Entradas sin id o con id vacío se ignoran, sin romper el resto. */
    public function test_ignora_entradas_sin_id()
    {
        $cached = array('registered_events' => array(
            array('name' => 'Sin id'),
            array('id' => '', 'name' => 'Id vacío'),
            array('id' => null),
            array('id' => 'evento-3'),
        ));
        $this->assertSame(array('evento-3'), sticpa_event_ids_from_calendar_cache($cached));
    }

    /** Tolerante a objetos, por si un filtro de terceros los inyecta. */
    public function test_acepta_objetos_ademas_de_arrays()
    {
        $event = new stdClass();
        $event->id = 'evento-4';
        $ids = sticpa_event_ids_from_calendar_cache(array('registered_events' => array($event)));
        $this->assertSame(array('evento-4'), $ids);
    }
}
