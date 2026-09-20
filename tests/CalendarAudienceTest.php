<?php

use PHPUnit\Framework\TestCase;

/**
 * EL CALENDARIO Y EL WIDGET DE LA HOME TAMBIÉN FILTRAN LA AUDIENCIA.
 *
 * Por qué existe este test. El filtro de audiencia nació para el listado de
 * Eventos (pages/list_stic_events.php) y allí se probó. Pero la consulta del
 * calendario (`sticpa_gather_calendar_data`) es OTRA, alimenta dos pantallas
 * más —el calendario y «Próximas actividades» de la home— y se traía todos los
 * eventos de la ventana: quien estaba en Castellón veía en su agenda las
 * convivencias de Vila-real, con su botón «Inscríbete». Reproducido con datos
 * reales del CRM el 20/09/2026.
 *
 * Es el fallo típico de este área: no da error, no se cae nada, simplemente se
 * ve lo que no es tuyo. Y es el que la doctrina de CLAUDE.md avisa que va a
 * volver, porque el CRM no lo evita —el área se conecta con un usuario técnico
 * y los grupos de seguridad no filtran ni una fila—. Así que cada consulta a
 * `stic_Events` necesita su prueba, y esta es la de aquí.
 */
class CalendarAudienceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-comunica-roles.php';
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/stic-event-audience.php';
        require_once __DIR__ . '/../inc/stic-pasar-lista.php';
        require_once __DIR__ . '/../inc/stic-pasar-lista-crm.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array(
            // Sin caché: lo que se prueba es la consulta, no el transient.
            'sticpa_calendar_cache_ttl' => 0,
        );
        $GLOBALS['__stic_transients'] = array();
        // Una persona de Castellón, con sus papeles ya resueltos en sesión para
        // que la audiencia no tenga que preguntar por ellos.
        $_SESSION = array(
            'scp_user_id' => 'c1',
            'scp_user_assigned_user_id' => 'deleg-castellon',
            'scp_relationship_raw' => '^participante_mic_com^',
            'scp_role_resolved' => true,
        );
    }

    /** Una fila del CRM, con la forma que devuelve la API. */
    private function evento(array $campos)
    {
        $nvl = new stdClass();
        foreach ($campos as $k => $v) {
            $nvl->$k = (object) array('value' => $v);
        }
        return (object) array('id' => $campos['id'], 'name_value_list' => $nvl);
    }

    private function nombres(array $eventos)
    {
        return array_map(function ($e) { return $e['name']; }, $eventos);
    }

    /**
     * EL CASO QUE SE VIO EN PANTALLA: las dos actividades de Vila-real no
     * aparecen en la agenda de alguien de Castellón, y la de Castellón sí.
     * Los datos son los del CRM del 20/09/2026, con sus claves de verdad
     * (`ajmcm_ambito_c = local`, que es la que usa el CRM, no una inventada).
     */
    public function testLaAgendaNoEnsenaLosEventosDeOtraDelegacion()
    {
        $scp = new FakeCalendarSCP(
            array('ajmcm_ambito_c' => array(), 'ajmcm_dirigido_a_c' => array(), 'ajmcm_filtro_edades_c' => array()),
            array(
                $this->evento(array(
                    'id' => 'ev-vil-1', 'name' => 'Convivencia Inicial 2026 · TBD · VIL',
                    'start_date' => '2026-10-23', 'assigned_user_id' => 'deleg-vila-real',
                    'ajmcm_ambito_c' => 'local',
                )),
                $this->evento(array(
                    'id' => 'ev-vil-2', 'name' => 'Registro de altas iniciales · VIL',
                    'start_date' => '2025-10-01', 'assigned_user_id' => 'deleg-vila-real',
                    'ajmcm_ambito_c' => 'local',
                )),
                $this->evento(array(
                    'id' => 'ev-cs-1', 'name' => 'COM | Convivencia Inicial 2026 · Buñol · CS',
                    'start_date' => '2026-10-16', 'assigned_user_id' => 'deleg-castellon',
                    'ajmcm_ambito_c' => 'local',
                )),
            )
        );

        $data = sticpa_gather_calendar_data($scp);

        $this->assertSame(
            array('COM | Convivencia Inicial 2026 · Buñol · CS'),
            $this->nombres($data['available_events'])
        );
    }

    /**
     * Un evento nacional se ve desde cualquier delegación. Es la otra dirección
     * del mismo fallo y hay que probarla: esconder de más deja el calendario
     * vacío y parece que el área está rota.
     */
    public function testLaAgendaSiEnsenaLosEventosNacionales()
    {
        $scp = new FakeCalendarSCP(
            array('ajmcm_ambito_c' => array()),
            array(
                $this->evento(array(
                    'id' => 'ev-nac', 'name' => 'Congreso de monitores',
                    'start_date' => '2026-12-09', 'assigned_user_id' => 'deleg-vila-real',
                    'ajmcm_ambito_c' => 'nacional',
                )),
            )
        );

        $data = sticpa_gather_calendar_data($scp);

        $this->assertSame(array('Congreso de monitores'), $this->nombres($data['available_events']));
    }

    /**
     * Un evento SIN ámbito y sin delegación (el «Administrador MCM» o nadie) es
     * de todos: así se comporta el listado y así tiene que comportarse la
     * agenda. Importa porque en el CRM hay eventos con el campo vacío.
     */
    public function testUnEventoSinAmbitoNiDelegacionSeVeDesdeCualquierSitio()
    {
        $scp = new FakeCalendarSCP(
            array('ajmcm_ambito_c' => array()),
            array(
                $this->evento(array(
                    'id' => 'ev-sin', 'name' => 'Actividad sin dueño',
                    'start_date' => '2026-11-11', 'assigned_user_id' => '1',
                )),
            )
        );

        $data = sticpa_gather_calendar_data($scp);

        $this->assertSame(array('Actividad sin dueño'), $this->nombres($data['available_events']));
    }

    /**
     * Y el otro eje: un evento de tu delegación dirigido SOLO a monitores no
     * sale en la agenda de una participante. El campo llega envuelto en
     * circunflejos aunque en el CRM sea un enum simple (comprobado el
     * 20/09/2026), que es justo lo que el troceador tiene que aguantar.
     */
    public function testLaAgendaRespetaElPerfilAlQueVaDirigido()
    {
        $scp = new FakeCalendarSCP(
            array('ajmcm_ambito_c' => array(), 'ajmcm_dirigido_a_c' => array()),
            array(
                $this->evento(array(
                    'id' => 'ev-mon', 'name' => 'Monitores | Reuniones de programación',
                    'start_date' => '2026-10-05', 'assigned_user_id' => 'deleg-castellon',
                    'ajmcm_ambito_c' => 'local', 'ajmcm_dirigido_a_c' => '^monitor^',
                )),
            )
        );

        $data = sticpa_gather_calendar_data($scp);

        $this->assertSame(array(), $data['available_events']);
    }

    /**
     * LO QUE SE LE PIDE AL CRM. `assigned_user_id` va siempre —sin él no hay
     * ámbito que juzgar, y el filtro se volvería un adorno— y los campos de
     * audiencia solo si EXISTEN, porque pedirle a `get_entry_list` una columna
     * que no está da error en algunas versiones en vez de ignorarla.
     */
    public function testPideLaDelegacionYSoloLosCamposDeAudienciaQueExisten()
    {
        $scp = new FakeCalendarSCP(array('ajmcm_ambito_c' => array()), array());

        sticpa_gather_calendar_data($scp);

        $this->assertContains('assigned_user_id', $scp->fieldsPedidos);
        $this->assertContains('ajmcm_ambito_c', $scp->fieldsPedidos);
        // Existe en el CRM de verdad, pero no en este doble: no se pide.
        $this->assertNotContains('ajmcm_dirigido_a_c', $scp->fieldsPedidos);
        // Y no se ha colado la ficha entera: la agenda no pinta descripciones.
        $this->assertNotContains('description', $scp->fieldsPedidos);
    }
}

/**
 * Doble del cliente del CRM para el calendario: la definición de campos (solo
 * los que "existen"), la consulta de eventos y ninguna inscripción.
 */
class FakeCalendarSCP
{
    public $fieldsPedidos = array();
    private $fieldDef;
    private $eventos;

    public function __construct(array $fieldDef = array(), array $eventos = array())
    {
        $this->fieldDef = $fieldDef;
        $this->eventos = $eventos;
    }

    public function getFieldDefinition($module, $fields)
    {
        $out = array();
        foreach ((array) $fields as $f) {
            if (array_key_exists($f, $this->fieldDef)) {
                $out[$f] = $this->fieldDef[$f];
            }
        }
        return (object) array('module_fields' => $out);
    }

    /** Sin inscripciones: lo que se prueba es la lista de "abiertos". */
    public function getRelatedElementsForLoggedUser($params)
    {
        return array();
    }

    public function getRecordsModule($module, $query = '', $fields = array(), $rel = null)
    {
        $this->fieldsPedidos = (array) $fields;
        return $this->eventos;
    }

    public function getRecordDetail($id, $module, $fields = null)
    {
        return new stdClass();
    }
}
