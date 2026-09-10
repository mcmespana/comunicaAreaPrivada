<?php

use PHPUnit\Framework\TestCase;

/**
 * AUDIENCIA DE LOS EVENTOS (inc/stic-event-audience.php).
 *
 * Lo que se prueba aquí son las decisiones que, si se rompen, no hacen ruido:
 * nadie recibe un error, simplemente alguien ve —o deja de ver— una actividad
 * que no le toca. Y las dos formas de fallar son igual de malas:
 *
 *   · esconder de más  →  el área parece rota («no hay eventos»);
 *   · esconder de menos →  un padre de Reus se apunta a la convivencia de
 *                          Castellón.
 *
 * Así que se prueban las dos direcciones, y sobre todo los casos de «no lo
 * sabemos», que son los que deciden hacia qué lado se falla.
 */
class EventAudienceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-comunica-roles.php';
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/stic-event-audience.php';
        // Pasar Lista presta tres piezas que este módulo reutiliza a propósito
        // en vez de duplicar: el troceador de multienum, la delegación de la
        // sesión y `sticpa_pl_mis_rels()`, que es LA llamada de la que salen
        // los cursos y los papeles de quien mira.
        require_once __DIR__ . '/../inc/stic-pasar-lista.php';
        require_once __DIR__ . '/../inc/stic-pasar-lista-crm.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_transients'] = array();
        $_SESSION = array(
            'scp_user_id' => 'c1',
            'scp_user_assigned_user_id' => 'deleg-castellon',
            'scp_relationship_raw' => '',
            'scp_role_resolved' => true,
        );
    }

    // -----------------------------------------------------------------
    // Ayudas
    // -----------------------------------------------------------------

    private function nvl(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    /** Quien mira, sin tocar el CRM: se le dan los datos hechos. */
    private function viewer(array $over = array())
    {
        return array_merge(array(
            'delegacion'        => 'deleg-castellon',
            'papeles'           => array('participante_mic_com'),
            'papeles_conocidos' => true,
            'cursos'            => array('5_primaria'),
            'cursos_conocidos'  => true,
        ), $over);
    }

    // -----------------------------------------------------------------
    // Lectura del evento
    // -----------------------------------------------------------------

    /** Los multienum del CRM vienen con acentos circunflejos de adorno. */
    public function testLeeLosMultienumDelCrm()
    {
        $a = sticpa_event_audience_from_nvl($this->nvl(array(
            'assigned_user_id' => 'deleg-castellon',
            'ajmcm_filtro_edades_c' => '^4_primaria^,^5_primaria^,^6_primaria^',
            'ajmcm_dirigido_a_c' => '^monitor^',
        )));
        $this->assertSame(array('4_primaria', '5_primaria', '6_primaria'), $a['cursos']);
        $this->assertSame(array('monitor'), $a['perfiles']);
        $this->assertSame('deleg-castellon', $a['delegacion']);
    }

    /** Un evento sin ninguno de los campos nuevos no restringe nada. */
    public function testUnEventoSinCamposNoRestringeNada()
    {
        $a = sticpa_event_audience_from_nvl($this->nvl(array('assigned_user_id' => 'deleg-castellon')));
        $this->assertSame(array(), $a['perfiles']);
        $this->assertSame(array(), $a['cursos']);
        $this->assertTrue(sticpa_event_audience_match($a, $this->viewer())['ok']);
    }

    /** En el CRM conviven `COM` y `com`: las claves se comparan en minúsculas. */
    public function testLasClavesNoDistinguenMayusculas()
    {
        $a = sticpa_event_audience_from_nvl($this->nvl(array(
            'assigned_user_id' => 'deleg-castellon',
            'ajmcm_dirigido_a_c' => '^Monitor^',
        )));
        $this->assertSame(array('monitor'), $a['perfiles']);
        $this->assertTrue(sticpa_event_audience_match($a, $this->viewer(array(
            'papeles' => array('MONITOR'),
        )))['ok']);
    }

    // -----------------------------------------------------------------
    // Ámbito: local o nacional
    // -----------------------------------------------------------------

    /** Sin campo de ámbito: con delegación es local, sin delegación es de todos. */
    public function testElAmbitoSeDeduceDeLaDelegacionCuandoNoSeDice()
    {
        $this->assertSame('local', sticpa_event_audience_scope(array('delegacion' => 'deleg-castellon', 'ambito' => '')));
        $this->assertSame('nacional', sticpa_event_audience_scope(array('delegacion' => '', 'ambito' => '')));
        // El «Administrador MCM» (id 1) es la oficina técnica, no una
        // delegación: lo que cuelga de él es de todos.
        $this->assertSame('nacional', sticpa_event_audience_scope(array('delegacion' => '1', 'ambito' => '')));
    }

    /** Y si el campo lo dice, manda el campo por encima de la delegación. */
    public function testElCampoDeAmbitoMandaSobreLaDelegacion()
    {
        $this->assertSame('nacional', sticpa_event_audience_scope(array('delegacion' => 'deleg-castellon', 'ambito' => 'nacional')));
        $this->assertSame('local', sticpa_event_audience_scope(array('delegacion' => '', 'ambito' => 'local')));
    }

    /** El caso que ocurría de verdad: la convivencia de otra delegación. */
    public function testUnEventoLocalDeOtraDelegacionNoEsParaMi()
    {
        $verdict = sticpa_event_audience_match(
            array('delegacion' => 'deleg-reus', 'ambito' => '', 'perfiles' => array(), 'cursos' => array()),
            $this->viewer()
        );
        $this->assertFalse($verdict['ok']);
        $this->assertSame('delegacion', $verdict['motivo']);
    }

    /** El congreso nacional, en cambio, sí: nadie lo tiene por delegación. */
    public function testElCongresoNacionalEsDeTodasLasDelegaciones()
    {
        $verdict = sticpa_event_audience_match(
            array('delegacion' => '', 'ambito' => 'nacional', 'perfiles' => array('monitor'), 'cursos' => array()),
            $this->viewer(array('delegacion' => 'deleg-reus', 'papeles' => array('monitor')))
        );
        $this->assertTrue($verdict['ok']);
    }

    /**
     * SIN SABER MI DELEGACIÓN NO SE ESCONDE NADA.
     *
     * Es la misma regla de seguridad que la casilla de Pasar Lista: si el
     * filtro actuara sin datos, el área entera se quedaría sin eventos y
     * parecería estropeada. Un fallo de sesión no puede vaciar la pantalla.
     */
    public function testSinSaberMiDelegacionNoSeEscondeNada()
    {
        $verdict = sticpa_event_audience_match(
            array('delegacion' => 'deleg-reus', 'ambito' => '', 'perfiles' => array(), 'cursos' => array()),
            $this->viewer(array('delegacion' => ''))
        );
        $this->assertTrue($verdict['ok']);
    }

    /** Y un evento local sin delegación asignada tampoco excluye a nadie. */
    public function testUnEventoSinDelegacionNoExcluyeANadie()
    {
        $verdict = sticpa_event_audience_match(
            array('delegacion' => '', 'ambito' => '', 'perfiles' => array(), 'cursos' => array()),
            $this->viewer(array('delegacion' => 'deleg-reus'))
        );
        $this->assertTrue($verdict['ok']);
    }

    // -----------------------------------------------------------------
    // Perfil
    // -----------------------------------------------------------------

    /** El congreso de monitores no se le ofrece a una participante. */
    public function testUnEventoDeMonitoresNoEsParaUnaParticipante()
    {
        $verdict = sticpa_event_audience_match(
            array('delegacion' => '', 'ambito' => 'nacional', 'perfiles' => array('monitor'), 'cursos' => array()),
            $this->viewer(array('papeles' => array('participante_mic_com')))
        );
        $this->assertFalse($verdict['ok']);
        $this->assertSame('perfil', $verdict['motivo']);
    }

    /** «Evento para miembros COM-LC»: se dice con `grupo`, que es la clave real. */
    public function testUnEventoDeMiembrosConGrupoEsParaQuienTieneGrupo()
    {
        $evento = array('delegacion' => '', 'ambito' => 'nacional', 'perfiles' => array('grupo'), 'cursos' => array());
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer(array('papeles' => array('grupo'))))['ok']);
        $this->assertFalse(sticpa_event_audience_match($evento, $this->viewer(array('papeles' => array('familiar_menor'))))['ok']);
    }

    /** Con varios perfiles marcados basta cumplir uno. */
    public function testConVariosPerfilesBastaCumplirUno()
    {
        $evento = array('delegacion' => '', 'ambito' => 'nacional',
            'perfiles' => array('monitor', 'coordinacion'), 'cursos' => array());
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer(array(
            'papeles' => array('coordinacion_mic_com'),
        )))['ok']);
    }

    /**
     * «NO TIENE PAPELES» y «NO SABEMOS SUS PAPELES» no son lo mismo.
     *
     * Es la distinción que en su día dejó a los monitores sin menú
     * (`scp_role_resolved`): un vacío resuelto es un dato, un vacío sin
     * resolver no. Aquí decide hacia qué lado se falla.
     */
    public function testUnVacioResueltoEsUnNoYUnVacioSinResolverNoEsconde()
    {
        $evento = array('delegacion' => '', 'ambito' => 'nacional', 'perfiles' => array('monitor'), 'cursos' => array());

        // El CRM contestó y esta persona no es nada: el evento no es para ella.
        $this->assertFalse(sticpa_event_audience_match($evento, $this->viewer(array(
            'papeles' => array(), 'papeles_conocidos' => true,
        )))['ok']);

        // No se pudo preguntar: no se esconde.
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer(array(
            'papeles' => array(), 'papeles_conocidos' => false,
        )))['ok']);
    }

    /**
     * Una clave del desplegable que no esté en el mapa casa consigo misma.
     * Si alguien añade un valor en el CRM y se olvida del mapa, el evento
     * sigue teniendo audiencia posible en vez de quedarse sin nadie.
     */
    public function testUnaClaveSinMapaCasaConsigoMisma()
    {
        $evento = array('delegacion' => '', 'ambito' => 'nacional',
            'perfiles' => array('socio_aj'), 'cursos' => array());
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer(array(
            'papeles' => array('socio_aj'),
        )))['ok']);
    }

    // -----------------------------------------------------------------
    // Curso escolar
    // -----------------------------------------------------------------

    /** Las sesiones del MIC son de 4.º a 6.º: 1.º de la ESO no entra. */
    public function testElCursoEscolarFiltraEntreParticipantes()
    {
        $evento = array('delegacion' => 'deleg-castellon', 'ambito' => '', 'perfiles' => array(),
            'cursos' => array('4_primaria', '5_primaria', '6_primaria'));
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer(array('cursos' => array('5_primaria'))))['ok']);

        $verdict = sticpa_event_audience_match($evento, $this->viewer(array('cursos' => array('1_eso'))));
        $this->assertFalse($verdict['ok']);
        $this->assertSame('curso', $verdict['motivo']);
    }

    /**
     * EL FILTRO DE CURSOS NO EXPULSA A QUIEN NO TIENE CURSO.
     *
     * Un monitor no tiene curso escolar propio, y las sesiones semanales del
     * MIC están marcadas de 4.º a 6.º de primaria: si el filtro se le
     * aplicara, los monitores del MIC se quedarían fuera de las sesiones del
     * MIC, que es donde tienen que estar para que se les pase lista.
     */
    public function testQuienNoTieneCursoNoQuedaFueraPorElCurso()
    {
        $evento = array('delegacion' => 'deleg-castellon', 'ambito' => '', 'perfiles' => array(),
            'cursos' => array('4_primaria', '5_primaria', '6_primaria'));
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer(array(
            'papeles' => array('monitor'), 'cursos' => array(), 'cursos_conocidos' => true,
        )))['ok']);
    }

    /** Los tres ejes son un Y: fallar uno basta para quedarse fuera. */
    public function testLosTresEjesSeCumplenALaVez()
    {
        $evento = array('delegacion' => 'deleg-castellon', 'ambito' => '',
            'perfiles' => array('participante_mic_com'), 'cursos' => array('5_primaria'));
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer())['ok']);
        // Mismo evento, otra delegación.
        $this->assertFalse(sticpa_event_audience_match($evento, $this->viewer(array(
            'delegacion' => 'deleg-reus',
        )))['ok']);
    }

    // -----------------------------------------------------------------
    // El interruptor y los filtros
    // -----------------------------------------------------------------

    /** Se puede apagar entero sin desplegar. */
    public function testSePuedeApagarConUnFiltro()
    {
        $GLOBALS['__stic_filters']['sticpa_event_audience_enabled'] = false;
        $evento = array('delegacion' => 'deleg-reus', 'ambito' => 'local',
            'perfiles' => array('monitor'), 'cursos' => array('1_eso'));
        $this->assertTrue(sticpa_event_audience_match($evento, $this->viewer())['ok']);
    }

    // -----------------------------------------------------------------
    // Filtrado de la lista y campos que se piden al CRM
    // -----------------------------------------------------------------

    /** El listado se queda solo con lo que es de quien mira. */
    public function testElListadoSeQuedaSoloConLoMio()
    {
        $filas = array();
        foreach (array(
            array('id' => 'e1', 'name' => 'Convivencia CS', 'assigned_user_id' => 'deleg-castellon'),
            array('id' => 'e2', 'name' => 'Convivencia Reus', 'assigned_user_id' => 'deleg-reus'),
            array('id' => 'e3', 'name' => 'Congreso monitores', 'assigned_user_id' => '1', 'ajmcm_dirigido_a_c' => '^monitor^'),
        ) as $campos) {
            $fila = new stdClass();
            $fila->name_value_list = $this->nvl($campos);
            $filas[] = $fila;
        }

        // Una participante de Castellón: se queda con la suya y pierde las
        // otras dos (la de Reus por delegación, el congreso por perfil).
        $GLOBALS['__stic_filters']['sticpa_viewer_audience'] = $this->viewer();
        $out = sticpa_filter_events_for_viewer(null, $filas);
        $this->assertCount(1, $out);
        $this->assertSame('e1', $out[0]->name_value_list->id->value);

        // Un monitor de Reus: la suya no está en la lista, y sí el congreso.
        $GLOBALS['__stic_filters']['sticpa_viewer_audience'] = $this->viewer(array(
            'delegacion' => 'deleg-reus', 'papeles' => array('monitor'), 'cursos' => array(),
        ));
        $out = sticpa_filter_events_for_viewer(null, $filas);
        $ids = array_map(function ($f) { return $f->name_value_list->id->value; }, $out);
        $this->assertSame(array('e2', 'e3'), $ids);
    }

    /**
     * Los campos de audiencia se le PIDEN al CRM, y solo los que existen.
     *
     * Es lo que permite desplegar esto antes de crear los campos: pedirle a
     * `get_entry_list` una columna que no está puede devolver error y dejar la
     * pantalla en blanco.
     */
    public function testSoloSePidenLosCamposQueExisten()
    {
        $scp = new FakeAudienceSCP(array(
            'id' => array(), 'name' => array(), 'status' => array(), 'type' => array(),
            'start_date' => array(), 'end_date' => array(), 'description' => array(),
            // De los de audiencia, en este CRM solo existe el de cursos.
            'ajmcm_filtro_edades_c' => array(),
            'price' => array(),
        ));
        $fields = sticpa_event_fields_to_request($scp);

        // `assigned_user_id` es base: la delegación del evento se pide siempre.
        $this->assertContains('assigned_user_id', $fields);
        $this->assertContains('ajmcm_filtro_edades_c', $fields);
        $this->assertNotContains('ajmcm_dirigido_a_c', $fields);
        $this->assertNotContains('ajmcm_ambito_c', $fields);
    }

    // -----------------------------------------------------------------
    // Lo que se le dice a la persona
    // -----------------------------------------------------------------

    /** Cada motivo tiene su explicación, y nunca se queda en blanco. */
    public function testCadaMotivoSeExplica()
    {
        $this->assertStringContainsString('otra delegación', sticpa_event_audience_notice('delegacion'));
        $this->assertStringContainsString('cursos', sticpa_event_audience_notice('curso'));
        $this->assertNotSame('', sticpa_event_audience_notice('perfil'));
        $this->assertSame('', sticpa_event_audience_notice(''));
    }

    /** Con las etiquetas del CRM el aviso dice para quién ES la actividad. */
    public function testElAvisoDePerfilNombraLosPerfilesDelEvento()
    {
        $texto = sticpa_event_audience_notice('perfil', array('monitores y monitoras'));
        $this->assertStringContainsString('monitores y monitoras', $texto);

        $this->assertSame(
            'monitores y monitoras y equipo de coordinación',
            sticpa_event_audience_join(array('monitores y monitoras', 'equipo de coordinación'))
        );
    }

    /** Y las etiquetas salen del CRM cuando el CRM las da. */
    public function testLasEtiquetasDeLosPerfilesSalenDelCrm()
    {
        $def = array('ajmcm_dirigido_a_c' => array('options' => array(
            'monitor' => array('value' => 'Monitores/as'),
        )));
        $textos = sticpa_event_audience_perfil_texts(array('perfiles' => array('monitor')), $def);
        $this->assertSame(array('Monitores/as'), $textos);

        // Sin definición del CRM se cae en las etiquetas de la casa.
        $textos = sticpa_event_audience_perfil_texts(array('perfiles' => array('monitor')), array());
        $this->assertSame(array('monitores y monitoras'), $textos);
    }

    // -----------------------------------------------------------------
    // Vigencia de las relaciones
    // -----------------------------------------------------------------

    /** Una relación terminada no dice lo que eres hoy. */
    public function testUnaRelacionTerminadaNoCuenta()
    {
        $GLOBALS['__stic_pl_now'] = mktime(12, 0, 0, 11, 15, 2025);
        $this->assertTrue(sticpa_event_audience_rel_vigente($this->nvl(array('end_date' => ''))));
        $this->assertTrue(sticpa_event_audience_rel_vigente($this->nvl(array('end_date' => '2026-09-30'))));
        $this->assertFalse(sticpa_event_audience_rel_vigente($this->nvl(array('end_date' => '2024-09-30'))));
        $this->assertFalse(sticpa_event_audience_rel_vigente($this->nvl(array('active' => '0'))));
        // Una fecha ilegible no cierra una relación.
        $this->assertTrue(sticpa_event_audience_rel_vigente($this->nvl(array('end_date' => 'no es una fecha'))));
        unset($GLOBALS['__stic_pl_now']);
    }

    /**
     * EL CURSO DE UNA RELACIÓN DE MONITOR ES EL DE SU GRUPO, NO EL SUYO.
     *
     * En los datos reales una monitora adulta tiene `5_primaria` en su
     * relación de monitora: es el curso del grupo que lleva. Contarlo como
     * suyo la metería en los eventos de 5.º de primaria y —peor— la dejaría
     * fuera de los de su edad.
     */
    public function testElCursoDeUnaRelacionDeMonitorNoEsSuCurso()
    {
        $scp = new FakeAudienceSCP(array(), array(
            $this->rel(array('relationship_type' => 'monitor', 'ajmcm_curso_escolar_c' => '5_primaria')),
            $this->rel(array('relationship_type' => 'participante_mic_com', 'ajmcm_curso_escolar_c' => '1_eso')),
        ));
        $viewer = sticpa_viewer_audience($scp, true);
        $this->assertSame(array('1_eso'), $viewer['cursos']);
        // Los PAPELES sí salen de las dos: es monitora y participante.
        $this->assertContains('monitor', $viewer['papeles']);
        $this->assertContains('participante_mic_com', $viewer['papeles']);
        $this->assertTrue($viewer['cursos_conocidos']);
    }

    /** Los +18 con relación de `grupo` sí aportan su curso (universitario). */
    public function testLaRelacionDeGrupoSiAportaCurso()
    {
        $scp = new FakeAudienceSCP(array(), array(
            $this->rel(array('relationship_type' => 'grupo', 'ajmcm_curso_escolar_c' => 'universitario')),
        ));
        $this->assertSame(array('universitario'), sticpa_viewer_audience($scp, true)['cursos']);
    }

    /** Y una relación terminada no aporta ni curso ni papel. */
    public function testUnaRelacionTerminadaNoAportaNada()
    {
        $GLOBALS['__stic_pl_now'] = mktime(12, 0, 0, 11, 15, 2025);
        $scp = new FakeAudienceSCP(array(), array(
            $this->rel(array('relationship_type' => 'monitor', 'end_date' => '2024-06-30')),
        ));
        $viewer = sticpa_viewer_audience($scp, true);
        $this->assertNotContains('monitor', $viewer['papeles']);
        unset($GLOBALS['__stic_pl_now']);
    }

    /** Si no hace falta, NO se pregunta por las relaciones. */
    public function testSinRestriccionesNoSePreguntaPorLasRelaciones()
    {
        $scp = new FakeAudienceSCP(array(), array());
        sticpa_viewer_audience($scp, false);
        $this->assertSame(0, $scp->relatedCalls);
    }

    // -----------------------------------------------------------------
    // La ficha: cuando no es para ti, no se ofrece el botón
    // -----------------------------------------------------------------

    /**
     * Con aviso de audiencia la ficha NO pinta «Inscribirme» y sí el motivo.
     *
     * A la ficha se llega por un enlace que alguien te pasa por WhatsApp, así
     * que enseñar el botón y que el guardado lo rechace después sería la peor
     * de las dos opciones.
     */
    public function testLaFichaNoOfreceInscribirseCuandoNoEsParaTi()
    {
        $event = sticpa_event_view_model($this->nvl(array(
            'id' => 'e9', 'name' => 'Congreso de monitores', 'start_date' => date('Y-m-d', strtotime('+2 months')),
            'assigned_user_id' => '1', 'ajmcm_dirigido_a_c' => '^monitor^',
        )));

        $abierta = sticpa_event_detail_html($event, 'Inscripción abierta', true, '');
        $this->assertStringContainsString('Inscribirme en esta actividad', $abierta);

        $cerrada = sticpa_event_detail_html($event, 'Inscripción abierta', true, 'Esta actividad es solo para monitores y monitoras.');
        $this->assertStringNotContainsString('Inscribirme en esta actividad', $cerrada);
        $this->assertStringContainsString('Esta actividad es solo para monitores y monitoras.', $cerrada);
        // Y tampoco se le ofrece «ver mi inscripción»: no la tiene.
        $this->assertStringNotContainsString('Ver mi inscripción', $cerrada);
        $this->assertStringContainsString('Ver otras actividades', $cerrada);
    }

    /**
     * «Ya estás inscrito» manda sobre la audiencia.
     *
     * A quien ya tiene su plaza no se le dice «esta actividad no es para ti»
     * aunque hoy no cumpla el filtro (le han cambiado el curso, se ha ido de
     * la delegación…). Primero lo que ES, después lo que puede hacer.
     */
    public function testYaEstarInscritoMandaSobreLaAudiencia()
    {
        $event = sticpa_event_view_model($this->nvl(array(
            'id' => 'e9', 'name' => 'Congreso de monitores',
            'start_date' => date('Y-m-d', strtotime('+2 months')),
            'assigned_user_id' => '1', 'ajmcm_dirigido_a_c' => '^monitor^',
        )));
        $html = sticpa_event_detail_html($event, '', false, 'Esta actividad es solo para monitores y monitoras.');
        $this->assertStringContainsString('Ya tienes una inscripción para esta actividad.', $html);
        $this->assertStringContainsString('Ver mi inscripción', $html);
        $this->assertStringNotContainsString('no es para ti', $html);
    }

    /** El modelo del evento lleva su audiencia, para que listado y ficha decidan igual. */
    public function testElModeloDelEventoLlevaSuAudiencia()
    {
        $event = sticpa_event_view_model($this->nvl(array(
            'id' => 'e9', 'name' => 'Sesiones MIC', 'assigned_user_id' => 'deleg-castellon',
            'ajmcm_filtro_edades_c' => '^4_primaria^,^5_primaria^',
        )));
        $this->assertSame(array('4_primaria', '5_primaria'), $event['audiencia']['cursos']);
        $this->assertSame('deleg-castellon', $event['audiencia']['delegacion']);
    }

    // -----------------------------------------------------------------
    // Los NOMBRES de los campos opcionales, y el 0 que no es un dato
    // -----------------------------------------------------------------

    /**
     * Los campos opcionales usan los nombres del CRM DE VERDAD.
     *
     * La lista pedía `capacity`, `start_time` y `registration_end`, que no
     * existen en este CRM: existen con otro nombre. O sea que el aforo, el
     * horario y la ventana de inscripción estaban rellenos y no se pintaban, y
     * la lista invitaba a crear campos duplicados.
     */
    public function testLosCamposOpcionalesUsanLosNombresDelCrm()
    {
        $campos = array_keys(sticpa_event_optional_fields());
        $this->assertContains('max_attendees', $campos);
        $this->assertContains('timetable', $campos);
        $this->assertContains('price', $campos);
        $this->assertNotContains('capacity', $campos);
        $this->assertNotContains('start_time', $campos);
        $this->assertNotContains('registration_end', $campos);

        // Y se le piden al CRM, junto a los dos de la ventana de inscripción.
        $scp = new FakeAudienceSCP(array(
            'id' => array(), 'name' => array(), 'max_attendees' => array(),
            'timetable' => array(), 'price' => array(),
            'ajmcm_start_inscripcion_c' => array(), 'ajmcm_end_inscripcion_c' => array(),
        ));
        $fields = sticpa_event_fields_to_request($scp);
        foreach (array('max_attendees', 'timetable', 'price', 'ajmcm_start_inscripcion_c', 'ajmcm_end_inscripcion_c') as $f) {
            $this->assertContains($f, $fields, $f . ' tiene que pedirse al CRM');
        }
    }

    /**
     * UN 0 NO SE ENSEÑA, porque no es una respuesta.
     *
     * Los cinco eventos del CRM tienen `max_attendees = 0` y `price = 0.00`:
     * es el valor por defecto de SuiteCRM. Sin esto la ficha decía «Plazas 0»
     * —que se lee como "no hay plazas", justo lo contrario de "sin límite"— y
     * «Precio 0,00 €» en todas las actividades. Y con el precio tampoco vale
     * poner «Gratis»: nadie ha dicho que lo sea, solo que está sin rellenar.
     */
    public function testUnCeroEnPlazasOPrecioNoSeEnsena()
    {
        $event = sticpa_event_view_model($this->nvl(array(
            'id' => 'e1', 'name' => 'Sesiones semanales',
            'max_attendees' => '0', 'price' => '0.00', 'timetable' => 'De 17 a 19 h',
        )));
        $this->assertArrayNotHasKey('max_attendees', $event['optional']);
        $this->assertArrayNotHasKey('price', $event['optional']);
        // El horario sí, que es texto libre y está relleno.
        $this->assertSame('De 17 a 19 h', $event['optional']['timetable']['text']);

        // Y con valores de verdad, sí se enseñan.
        $event = sticpa_event_view_model($this->nvl(array(
            'id' => 'e1', 'name' => 'Campamento', 'max_attendees' => '60', 'price' => '285.00',
        )));
        $this->assertSame('60', $event['optional']['max_attendees']['text']);
        $this->assertStringContainsString('285', $event['optional']['price']['text']);
    }

    // -----------------------------------------------------------------
    // La ventana de inscripción
    // -----------------------------------------------------------------

    /** Sin fechas, la inscripción está ABIERTA. */
    public function testSinFechasLaInscripcionEstaAbierta()
    {
        $w = sticpa_event_registration_window($this->nvl(array('name' => 'X')));
        $this->assertSame('sin_datos', $w['estado']);
        $this->assertTrue($w['abierta']);
        // Y no se cuenta nada en pantalla: no hay nada que contar.
        $this->assertNull(sticpa_event_registration_fact($w));
        $this->assertSame('', sticpa_event_registration_note($w));
    }

    /** Antes de abrirse, dentro de plazo y pasado el plazo. */
    public function testLosTresEstadosDeLaVentana()
    {
        $antes = sticpa_event_registration_window($this->nvl(array(
            'ajmcm_start_inscripcion_c' => date('Y-m-d', strtotime('+10 days')),
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('+30 days')),
        )));
        $this->assertSame('antes', $antes['estado']);
        $this->assertFalse($antes['abierta']);
        $this->assertStringContainsString('Se abre el', sticpa_event_registration_fact($antes)['text']);
        $this->assertStringContainsString('se abre el', sticpa_event_registration_note($antes));

        $abierta = sticpa_event_registration_window($this->nvl(array(
            'ajmcm_start_inscripcion_c' => date('Y-m-d', strtotime('-10 days')),
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('+10 days')),
        )));
        $this->assertSame('abierta', $abierta['estado']);
        $this->assertTrue($abierta['abierta']);
        $this->assertStringContainsString('Hasta el', sticpa_event_registration_fact($abierta)['text']);
        $this->assertSame('', sticpa_event_registration_note($abierta));

        $cerrada = sticpa_event_registration_window($this->nvl(array(
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('-1 day')),
        )));
        $this->assertSame('cerrada', $cerrada['estado']);
        $this->assertFalse($cerrada['abierta']);
        $this->assertStringContainsString('terminó el', sticpa_event_registration_note($cerrada));
    }

    /**
     * EL DÍA DE FIN CUENTA ENTERO. Un plazo «hasta el 25» que cierra a las
     * 00:00 del 25 le roba un día a la gente.
     */
    public function testElUltimoDiaDelPlazoCuentaEntero()
    {
        $hoy = sticpa_event_registration_window($this->nvl(array(
            'ajmcm_end_inscripcion_c' => date('Y-m-d'),
        )));
        $this->assertSame('abierta', $hoy['estado']);
        $this->assertTrue($hoy['abierta']);
    }

    /** Abierta y sin fecha de cierre: no se cuenta nada, no es información. */
    public function testAbiertaSinFechaDeCierreNoCuentaNada()
    {
        $w = sticpa_event_registration_window($this->nvl(array(
            'ajmcm_start_inscripcion_c' => date('Y-m-d', strtotime('-10 days')),
        )));
        $this->assertSame('abierta', $w['estado']);
        $this->assertNull(sticpa_event_registration_fact($w));
    }

    /**
     * Fuera de plazo, la tarjeta no ofrece «Inscribirme» y el CHIP lo dice.
     *
     * Y el chip dice «Inscripción cerrada» aunque el `status` del CRM siga en
     * `registration`: cuando hay fechas, mandan las fechas. Sin esto la
     * tarjeta decía «INSCRIPCIÓN ABIERTA» sin botón, que es la clase de
     * pantalla que hace que nadie se crea nada de lo que pone.
     *
     * La FECHA de cierre no se repite en la lista —el chip ya lo ha dicho, y
     * la fecha exacta de algo que se pasó no sirve para nada ahí—; en la ficha
     * sí sale, que es donde uno va a mirar cuándo se le pasó.
     */
    public function testFueraDePlazoNoHayBotonYElChipLoDice()
    {
        $fila = new stdClass();
        $fila->name_value_list = $this->nvl(array(
            'id' => 'e1', 'name' => 'Convivencia de inicio',
            'start_date' => date('Y-m-d', strtotime('+2 months')),
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('-1 day')),
        ));
        $html = sticpa_events_list_html(array($fila), array('registration' => 'Inscripción abierta'));
        $this->assertStringNotContainsString('Inscribirme', $html);
        $this->assertStringContainsString('Inscripción cerrada', $html);
        $this->assertStringNotContainsString('Inscripción abierta', $html);
        $this->assertStringNotContainsString('Cerrada el', $html);
        $this->assertStringContainsString('Ver detalle', $html);
    }

    /** Y antes de abrirse, igual: el chip lo dice y la línea da la fecha. */
    public function testAntesDeAbrirseElChipLoDiceYLaLineaDaLaFecha()
    {
        $fila = new stdClass();
        $fila->name_value_list = $this->nvl(array(
            'id' => 'e2', 'name' => 'Campamento de verano',
            'start_date' => date('Y-m-d', strtotime('+6 months')),
            'ajmcm_start_inscripcion_c' => date('Y-m-d', strtotime('+1 month')),
        ));
        $html = sticpa_events_list_html(array($fila), array('registration' => 'Inscripción abierta'));
        $this->assertStringNotContainsString('Inscribirme', $html);
        $this->assertStringContainsString('Inscripción no abierta', $html);
        $this->assertStringContainsString('Se abre el', $html);
    }

    /**
     * LA DURACIÓN SOLO SE CUENTA SI ES UNA DURACIÓN.
     *
     * «Sesiones semanales 2026-2027» va de septiembre a junio y la ficha decía
     * «Duración: 231 días»: es cierto y no significa nada. Eso no es una
     * actividad de 231 días, es un curso entero.
     */
    public function testLaDuracionNoSeCuentaEnUnCursoEntero()
    {
        $curso = sticpa_event_view_model($this->nvl(array(
            'id' => 'e4', 'name' => 'Sesiones semanales',
            'start_date' => date('Y-m-d', strtotime('-1 month')),
            'end_date' => date('Y-m-d', strtotime('+7 months')),
        )));
        $this->assertStringNotContainsString('Duración', sticpa_event_detail_html($curso));

        // Un campamento de diez días sí la cuenta: ahí el número dice algo.
        $campa = sticpa_event_view_model($this->nvl(array(
            'id' => 'e5', 'name' => 'Campamento',
            'start_date' => date('Y-m-d', strtotime('+2 months')),
            'end_date' => date('Y-m-d', strtotime('+2 months +9 days')),
        )));
        $html = sticpa_event_detail_html($campa);
        $this->assertStringContainsString('Duración', $html);
        $this->assertStringContainsString('10 días', $html);
    }

    /** Y dentro de plazo sí lo ofrece, con la fecha límite a la vista. */
    public function testDentroDePlazoHayBotonYFechaLimite()
    {
        $fila = new stdClass();
        $fila->name_value_list = $this->nvl(array(
            'id' => 'e1', 'name' => 'Convivencia de inicio',
            'start_date' => date('Y-m-d', strtotime('+2 months')),
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('+10 days')),
        ));
        $html = sticpa_events_list_html(array($fila));
        $this->assertStringContainsString('Inscribirme', $html);
        $this->assertStringContainsString('Hasta el', $html);
    }

    // -----------------------------------------------------------------
    // La fuente única: audiencia + plazo, y en ese orden
    // -----------------------------------------------------------------

    /**
     * LA AUDIENCIA SE DICE ANTES QUE EL PLAZO, y no es un detalle: a quien es
     * de otra delegación, decirle «el plazo terminó» le hace pensar que llegó
     * tarde a algo que nunca fue suyo.
     */
    public function testLaAudienciaSeExplicaAntesQueElPlazo()
    {
        $GLOBALS['__stic_filters']['sticpa_viewer_audience'] = $this->viewer(array(
            'delegacion' => 'deleg-reus',
        ));
        $nvl = $this->nvl(array(
            'id' => 'e1', 'name' => 'Convivencia CS', 'assigned_user_id' => 'deleg-castellon',
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('-1 day')),
        ));
        $block = sticpa_event_signup_block(null, 'e1', $nvl);
        $this->assertTrue($block['bloqueado']);
        $this->assertStringContainsString('otra delegación', $block['texto']);
        $this->assertSame('Esta actividad no es para ti', $block['titulo']);
    }

    /** Siendo de la delegación, el motivo que queda es el plazo. */
    public function testSiendoDeLaDelegacionElMotivoEsElPlazo()
    {
        $GLOBALS['__stic_filters']['sticpa_viewer_audience'] = $this->viewer();
        $nvl = $this->nvl(array(
            'id' => 'e1', 'name' => 'Convivencia CS', 'assigned_user_id' => 'deleg-castellon',
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('-1 day')),
        ));
        $block = sticpa_event_signup_block(null, 'e1', $nvl);
        $this->assertTrue($block['bloqueado']);
        $this->assertSame('La inscripción no está abierta', $block['titulo']);
        $this->assertStringContainsString('terminó el', $block['texto']);
    }

    /** Y si todo cuadra, no bloquea nada. */
    public function testSiTodoCuadraNoSeBloquea()
    {
        $GLOBALS['__stic_filters']['sticpa_viewer_audience'] = $this->viewer();
        $nvl = $this->nvl(array(
            'id' => 'e1', 'name' => 'Convivencia CS', 'assigned_user_id' => 'deleg-castellon',
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('+10 days')),
        ));
        $block = sticpa_event_signup_block(null, 'e1', $nvl);
        $this->assertFalse($block['bloqueado']);
        $this->assertSame('', $block['texto']);
    }

    private function rel(array $campos)
    {
        $fila = new stdClass();
        $fila->name_value_list = $this->nvl($campos);
        return $fila;
    }
}

/**
 * Doble del cliente del CRM, con lo justo: la definición de campos del módulo
 * de eventos y las relaciones de una persona. Cuenta las llamadas, porque el
 * coste de esta pantalla es parte de lo que se prueba.
 */
class FakeAudienceSCP
{
    public $relatedCalls = 0;
    private $fieldDef;
    private $rels;

    public function __construct(array $fieldDef = array(), array $rels = array())
    {
        $this->fieldDef = $fieldDef;
        $this->rels = $rels;
    }

    public function getFieldDefinition($module, $fields)
    {
        // Como el CRM: solo devuelve los campos que EXISTEN.
        $out = array();
        foreach ((array) $fields as $f) {
            if (array_key_exists($f, $this->fieldDef)) {
                $out[$f] = $this->fieldDef[$f];
            }
        }
        return (object) array('module_fields' => $out);
    }

    public function getRelatedElementsForLoggedUser($params)
    {
        $this->relatedCalls++;
        return $this->rels;
    }

    public function getRecordDetail($id, $module, $fields = null)
    {
        return new stdClass();
    }
}
