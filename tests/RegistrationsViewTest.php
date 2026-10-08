<?php

use PHPUnit\Framework\TestCase;

/**
 * INSCRIPCIONES (inc/stic-registrations.php).
 *
 * Lo que se prueba son las decisiones que se toman una vez y luego se olvidan,
 * y que si se rompen no salta nada: qué fecha manda en la tarjeta, qué pasa
 * cuando la caché del calendario está fría, y que la pantalla no pide al CRM
 * más de lo que enseña.
 */
class RegistrationsViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-registrations.php';
        require_once __DIR__ . '/../inc/stic-formatter.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_transients'] = array();
        $_SESSION['scp_user_id'] = 'c1';
    }

    /** Construye un name_value_list como el que devuelve el CRM. */
    private function nvl(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    private function row(array $fields)
    {
        $r = new stdClass();
        $r->name_value_list = $this->nvl($fields);
        return $r;
    }

    private function definition()
    {
        return array('status' => array('options' => array(
            'Confirmed' => array('value' => 'Confirmada'),
            'pending'   => array('value' => 'Pendiente de confirmar'),
            'cancelled' => array('value' => 'Cancelada'),
        )));
    }

    private function calentarCalendario()
    {
        set_transient(sticpa_calendar_cache_key(), array('registered_events' => array(
            array('id' => 'ev-1', 'name' => 'Campamento de verano 2026', 'start' => '2026-07-01', 'end' => '2026-07-10'),
        )), 300);
    }

    /**
     * LA decisión de esta pantalla: en la tarjeta manda la fecha del EVENTO,
     * no la fecha en que te apuntaste. Antes era al revés y era el fallo de
     * fondo del listado.
     */
    public function testConElCalendarioCalienteMandaLaFechaDelEvento()
    {
        $this->calentarCalendario();
        $reg = sticpa_registration_view_model($this->nvl(array(
            'id' => 'r1', 'name' => 'INS-1', 'status' => 'Confirmed',
            'registration_date' => '2026-03-14 10:00:00',
            'stic_registrations_stic_events_name' => 'Campamento de verano 2026',
            'stic_registrations_stic_eventsstic_events_ida' => 'ev-1',
        )), sticpa_registration_event_index());

        $this->assertSame(strtotime('2026-07-01'), $reg['start_ts']);
        $when = sticpa_registration_when_line($reg);
        $this->assertSame('calendar', $when['icon']);
        $this->assertStringNotContainsString('apuntaste', $when['text']);
    }

    /**
     * Con la caché fría no se pide nada al CRM: se enseña lo que hay, y se
     * dice CLARO que es la fecha en que te apuntaste, para que nadie la
     * confunda con la fecha de la actividad.
     */
    public function testConElCalendarioFrioSeDegradaSinPedirNada()
    {
        $reg = sticpa_registration_view_model($this->nvl(array(
            'id' => 'r1', 'name' => 'INS-1', 'status' => 'Confirmed',
            'registration_date' => '2026-03-14 10:00:00',
            'stic_registrations_stic_events_name' => 'Campamento de verano 2026',
            'stic_registrations_stic_eventsstic_events_ida' => 'ev-1',
        )), sticpa_registration_event_index());

        $this->assertNull($reg['start_ts']);
        $this->assertSame(strtotime('2026-03-14 10:00:00'), $reg['signed_ts']);
        $this->assertStringContainsString('apuntaste', sticpa_registration_when_line($reg)['text']);
        // Y el enlace al evento sigue funcionando: sale del registro, no de la caché.
        $this->assertSame('ev-1', $reg['event_id']);
    }

    /** El título es el del EVENTO, no el código administrativo del CRM. */
    public function testElTituloEsElDelEvento()
    {
        $reg = sticpa_registration_view_model($this->nvl(array(
            'id' => 'r1', 'name' => 'INS-000123',
            'stic_registrations_stic_events_name' => 'Campamento de verano 2026',
        )));
        $this->assertSame('Campamento de verano 2026', $reg['title']);
        // Sin evento, el nombre propio es mejor que nada.
        $sinEvento = sticpa_registration_view_model($this->nvl(array('id' => 'r2', 'name' => 'INS-000124')));
        $this->assertSame('INS-000124', $sinEvento['title']);
    }

    /**
     * EL PARAGUAS «OTROS EVENTOS» (30/09/2026): la inscripción de un evento
     * oculto sigue en la lista, pero plegada debajo de lo principal y contada.
     * Sin eventos ocultos, la lista es exactamente la de antes.
     */
    public function testLasInscripcionesDeEventosOcultosVanBajoOtrosEventos()
    {
        $filas = array(
            $this->row(array('id' => 'r-a', 'name' => 'A', 'status' => 'pending',
                'stic_registrations_stic_events_name' => 'Actividad normal',
                'stic_registrations_stic_eventsstic_events_ida' => 'ev-normal')),
            $this->row(array('id' => 'r-b', 'name' => 'B', 'status' => 'pending',
                'stic_registrations_stic_events_name' => 'Reunión de monitores',
                'stic_registrations_stic_eventsstic_events_ida' => 'ev-oculto')),
        );

        $html = sticpa_registrations_list_html($filas, $this->definition(), array('ev-oculto' => true));
        $this->assertStringContainsString('stic-rec-other', $html);
        $this->assertStringContainsString('Otros eventos', $html);
        $this->assertLessThan(strpos($html, 'Reunión de monitores'), strpos($html, 'Actividad normal'));
        $this->assertGreaterThan(strpos($html, 'Otros eventos'), strpos($html, 'Reunión de monitores'));
        $this->assertStringNotContainsString(' open', substr($html, strpos($html, '<details'), 40));

        // Sin ocultos: nada de paraguas.
        $sin = sticpa_registrations_list_html($filas, $this->definition());
        $this->assertStringNotContainsString('stic-rec-other', $sin);
        $this->assertStringContainsString('Reunión de monitores', $sin);

        // Si TODO está oculto, el paraguas sale abierto (no parece una lista vacía).
        $todo = sticpa_registrations_list_html(array($filas[1]), $this->definition(), array('ev-oculto' => true));
        $this->assertStringContainsString('<details class=\'stic-rec-other\' open>', $todo);
    }

    /** Sin el campo en el CRM no se consulta nada y no hay nada oculto. */
    public function testSinElCampoNoSeConsultaNada()
    {
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/stic-event-audience.php';
        $scp = new class {
            public $llamadas = 0;
            public function getFieldDefinition($m, $f) { return (object) array('module_fields' => array('name' => array())); }
            public function getRecordsModule($m, $q = '', $f = array(), $r = null) { $this->llamadas++; return array(); }
        };
        $this->assertSame(array(), sticpa_registration_hidden_event_ids($scp));
        $this->assertSame(0, $scp->llamadas);
    }

    /** Lo cancelado y lo ya celebrado no encabeza la lista. */
    public function testLoCerradoSeVaAbajo()
    {
        $this->calentarCalendario();
        $html = sticpa_registrations_list_html(array(
            $this->row(array('id' => 'r-cancel', 'name' => 'X', 'status' => 'cancelled',
                'registration_date' => '2020-01-01 00:00:00',
                'stic_registrations_stic_events_name' => 'Actividad cancelada')),
            $this->row(array('id' => 'r-viva', 'name' => 'Y', 'status' => 'pending',
                'registration_date' => '2026-04-02 00:00:00',
                'stic_registrations_stic_events_name' => 'Actividad viva')),
        ), $this->definition());

        $this->assertLessThan(
            strpos($html, 'Actividad cancelada'),
            strpos($html, 'Actividad viva'),
            'Lo que sigue vivo va antes que lo cancelado'
        );
        // Y la cancelada se apaga, como lo ya celebrado.
        $this->assertStringContainsString('stic-rec-card is-past', $html);
    }

    /**
     * La tarjeta NO lleva barra de acciones: la tarjeta entera ya abre la
     * ficha, y un botón que hace lo mismo se lleva el degradado de marca y
     * ~50px por tarjeta. Se decidió mirando la captura; que no vuelva sin que
     * alguien lo decida otra vez.
     */
    public function testLaTarjetaNoRepiteSuPropioEnlaceComoBoton()
    {
        $this->calentarCalendario();
        $html = sticpa_registrations_list_html(array(
            $this->row(array('id' => 'r1', 'name' => 'X', 'status' => 'Confirmed',
                'registration_date' => '2026-03-14 00:00:00',
                'stic_registrations_stic_events_name' => 'Campamento de verano 2026',
                'stic_registrations_stic_eventsstic_events_ida' => 'ev-1')),
        ), $this->definition());

        $this->assertStringNotContainsString('stic-rec-actions', $html);
        $this->assertStringNotContainsString('stic-rec-btn--primary', $html);
        $this->assertStringContainsString('stic-rec-main', $html);
    }

    /** Nunca se enseña la clave cruda del desplegable ("Confirmed"). */
    public function testElEstadoSeEnsenaTraducido()
    {
        $this->calentarCalendario();
        $html = sticpa_registrations_list_html(array(
            $this->row(array('id' => 'r1', 'name' => 'X', 'status' => 'Confirmed',
                'registration_date' => '2026-03-14 00:00:00',
                'stic_registrations_stic_events_name' => 'Campamento de verano 2026')),
        ), $this->definition());

        $this->assertStringContainsString('Confirmada', $html);
        $this->assertStringNotContainsString('>Confirmed<', $html);
        $this->assertStringContainsString('stic-rec-chip--ok', $html);
    }

    /** «No invitado» es interno: la persona está inscrita y no se le dice (08/10/2026). */
    public function testNoInvitadoNoSeEnseña()
    {
        $this->calentarCalendario();
        $def = $this->definition();
        $def['status']['options']['uninvited'] = array('value' => 'No invitado');
        $html = sticpa_registrations_list_html(array(
            $this->row(array('id' => 'r1', 'name' => 'X', 'status' => 'uninvited',
                'registration_date' => '2026-03-14 00:00:00',
                'stic_registrations_stic_events_name' => 'Campamento de verano 2026')),
        ), $def);
        $this->assertStringContainsString('Campamento de verano 2026', $html);
        $this->assertStringNotContainsString('No invitado', $html);
    }

    /** La lista dice qué es y lleva a Eventos para lo que aún no te has apuntado. */
    public function testLaListaExplicaQueEsYEnlazaAEventos()
    {
        $this->calentarCalendario();
        $html = sticpa_registrations_list_html(array(
            $this->row(array('id' => 'r1', 'name' => 'X', 'status' => 'Confirmed',
                'registration_date' => '2026-03-14 00:00:00',
                'stic_registrations_stic_events_name' => 'Campamento de verano 2026')),
        ), $this->definition());
        $this->assertStringContainsString('a los que te has apuntado', $html);
        $this->assertStringContainsString("href='?internalpage=list_stic_events'", $html);
    }

    /** Un estado que el CRM no sabe traducir no pinta un chip con el código. */
    public function testUnEstadoDesconocidoNoPintaChip()
    {
        $this->calentarCalendario();
        $html = sticpa_registrations_list_html(array(
            $this->row(array('id' => 'r1', 'name' => 'X', 'status' => 'estado_raro_del_crm',
                'registration_date' => '2026-03-14 00:00:00',
                'stic_registrations_stic_events_name' => 'Campamento de verano 2026')),
        ), $this->definition());
        $this->assertStringNotContainsString('estado_raro_del_crm', $html);
    }

    /** El listado pide MENOS campos que la ficha: cada campo viaja por fila. */
    public function testElListadoPideMenosCamposQueLaFicha()
    {
        $lista = sticpa_registration_list_fields();
        $ficha = sticpa_registration_detail_fields();
        $this->assertLessThan(count($ficha), count($lista));
        $this->assertEmpty(array_diff($lista, $ficha), 'La ficha incluye todo lo del listado');
        // El id del enlace al evento es lo que hace posible "Ver la actividad".
        $this->assertContains('stic_registrations_stic_eventsstic_events_ida', $lista);
    }

    /** Sin inscripciones, la pantalla ofrece por dónde seguir. */
    public function testElVacioLlevaAEventos()
    {
        $html = sticpa_registrations_list_html(array(), $this->definition());
        $this->assertStringContainsString('stic-empty-state', $html);
        $this->assertStringContainsString('list_stic_events', $html);
    }

    /** Un tutor sin nombre no pinta una fila vacía. */
    public function testUnTutorSinNombreNoSePinta()
    {
        $nvl = $this->nvl(array('ajmcm_tutor1_phone_c' => '600111222'));
        $this->assertNull(sticpa_registration_tutor($nvl, 'ajmcm_tutor1', array()));

        $conNombre = $this->nvl(array(
            'ajmcm_tutor1_firstname_c' => 'Marta', 'ajmcm_tutor1_lastname_c' => 'Ruiz',
            'ajmcm_tutor1_phone_c' => '600111222',
        ));
        $tutor = sticpa_registration_tutor($conNombre, 'ajmcm_tutor1', array());
        $this->assertSame('Marta Ruiz', $tutor['nombre']);
    }

    /** Nombres de evento con acentos o espacios de más siguen casando. */
    public function testElNombreDeEventoSeNormalizaParaComparar()
    {
        $this->assertSame(
            sticpa_registration_name_key('Convivención  de Inicio'),
            sticpa_registration_name_key('convivencion de inicio')
        );
    }

    /** La fecha de inscripción no se dice dos veces en la misma pantalla. */
    public function testLaFechaDeInscripcionNoSeRepiteEnLaFicha()
    {
        // Calendario frío: la cabecera ya dice "Te apuntaste el…".
        $reg = sticpa_registration_view_model($this->nvl(array(
            'id' => 'r1', 'name' => 'INS-1', 'status' => 'pending',
            'registration_date' => '2026-04-02 09:00:00',
            'stic_registrations_stic_events_name' => 'Taller',
        )), array());
        $html = sticpa_registration_detail_html($reg, $this->definition());
        $this->assertSame(1, substr_count($html, 'apuntaste'));
        $this->assertStringNotContainsString('Fecha de inscripción', $html);
    }

    /** EV-11: el curso y la clase, con la etiqueta del desplegable y no con su clave. */
    public function test_el_curso_y_la_clase_salen_con_su_etiqueta()
    {
        $def = $this->definition() + array(
            'ajmcm_curso_escolar_c' => array('options' => array('3_eso' => array('value' => '3.º ESO'))),
            'ajmcm_clase_c' => array('options' => array('c1' => array('value' => 'C1'))),
        );
        $row = $this->row(array('id' => 'r1', 'name' => 'INS-1', 'status' => 'Confirmed',
            'stic_registrations_stic_events_name' => 'Convivencia', 'ajmcm_curso_escolar_c' => '3_eso', 'ajmcm_clase_c' => 'c1'));
        $lista = sticpa_registrations_list_html(array($row), $def);
        $this->assertStringContainsString('3.º ESO · C1', $lista);
        $this->assertStringNotContainsString('3_eso', $lista);

        $ficha = sticpa_registration_detail_html(sticpa_registration_view_model($row->name_value_list), $def);
        $this->assertStringContainsString('3.º ESO', $ficha);
        $this->assertStringNotContainsString('3_eso', $ficha);
    }

    public function test_no_aplica_no_es_un_curso_y_sin_etiqueta_vale_la_clave()
    {
        $this->assertSame('', sticpa_registration_aula_label(array(), 'ajmcm_curso_escolar_c', 'na'));
        $this->assertSame('otros', sticpa_registration_aula_label(array(), 'ajmcm_curso_escolar_c', 'otros'));
    }
}
