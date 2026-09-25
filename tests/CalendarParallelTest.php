<?php

use PHPUnit\Framework\TestCase;

/**
 * PLAN 011 — EL CALENDARIO PIDE CADA NIVEL EN UNA TANDA (inc/stic-calendar.php).
 *
 * `sticpa_gather_calendar_data()` alimenta la portada y el calendario. Eran
 * `2 + 2N + N×M` llamadas en fila; ahora son las mismas consultas, pero cada
 * nivel sale en paralelo. Lo que importa comprobar es que el RESULTADO no
 * cambia: mismo array con tandas que en serie, incluido el respaldo de las
 * asistencias por nombre de sesión, que depende del orden de proceso.
 */
class CalendarParallelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-events.php';
    }

    protected function setUp(): void
    {
        $_SESSION = array('scp_user_id' => 'yo', 'scp_module' => 'Contacts');
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_filters'] = array();
        // Sin caché: cada llamada a la función va al CRM falso.
        add_filter('sticpa_calendar_cache_ttl', function () { return 0; });
    }

    /** Los datos del CRM falso: tres inscripciones, una cancelada. */
    private function datos()
    {
        $row = function (array $f) {
            $nvl = new stdClass();
            foreach ($f as $k => $v) {
                $nvl->$k = (object) array('value' => $v);
            }
            return (object) array('id' => $f['id'], 'name_value_list' => $nvl);
        };
        return array(
            'Contacts:yo:stic_registrations_contacts' => array(
                $row(array('id' => 'r1', 'status' => 'confirmed')),
                $row(array('id' => 'r2', 'status' => 'confirmed')),
                $row(array('id' => 'r3', 'status' => 'cancelled')),
            ),
            'stic_Registrations:r1:stic_registrations_stic_events' => array(
                $row(array('id' => 'e1', 'name' => 'Sábados MIC', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30')),
            ),
            // El mismo evento en dos inscripciones: se consultaba dos veces.
            'stic_Registrations:r2:stic_registrations_stic_events' => array(
                $row(array('id' => 'e1', 'name' => 'Sábados MIC', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30')),
                $row(array('id' => 'e2', 'name' => 'Convivencia', 'start_date' => '2026-10-10', 'end_date' => '2026-10-11')),
            ),
            'stic_Events:e1:stic_sessions_stic_events' => array(
                $row(array('id' => 's1', 'name' => 'Sábado 1', 'start_date' => '2026-09-05 10:00', 'end_date' => '2026-09-05 12:00')),
                $row(array('id' => 's2', 'name' => 'Sábado 2', 'start_date' => '2026-09-12 10:00', 'end_date' => '2026-09-12 12:00')),
            ),
            'stic_Events:e2:stic_sessions_stic_events' => array(
                $row(array('id' => 's3', 'name' => 'Día 1', 'start_date' => '2026-10-10 10:00', 'end_date' => '2026-10-10 20:00')),
            ),
            'stic_Registrations:r1:stic_attendances_stic_registrations' => array(
                $row(array('id' => 'a1', 'status' => 'yes', 'start_date' => '', 'stic_attendances_stic_sessionsstic_sessions_ida' => 's1', 'stic_attendances_stic_sessions_name' => 'Sábado 1')),
                // Sin id de sesión: casa por nombre único («Sábado 2»).
                $row(array('id' => 'a2', 'status' => 'no', 'start_date' => '', 'stic_attendances_stic_sessionsstic_sessions_ida' => '', 'stic_attendances_stic_sessions_name' => 'Sábado 2')),
            ),
            'stic_Registrations:r2:stic_attendances_stic_registrations' => array(
                $row(array('id' => 'a3', 'status' => 'yes', 'start_date' => '', 'stic_attendances_stic_sessionsstic_sessions_ida' => 's3', 'stic_attendances_stic_sessions_name' => 'Día 1')),
            ),
            // Una cancelada no se consulta nunca.
            'stic_Registrations:r3:stic_registrations_stic_events' => array(
                $row(array('id' => 'e9', 'name' => 'No debe salir', 'start_date' => '2026-09-01', 'end_date' => '2026-09-02')),
            ),
        );
    }

    /** CRM falso. Con $paralelo sabe hacer tandas como el de verdad. */
    private function crm($paralelo)
    {
        $datos = $this->datos();
        $base = new class($datos) {
            public $calls = array();
            public $batches = array();
            protected $datos;
            protected $recolectando = false;
            protected $recolectado = array();
            protected $traido = array();
            public function __construct($datos) { $this->datos = $datos; }
            public function getRelatedElementsForLoggedUser($p)
            {
                $sig = $p['module_name'] . ':' . $p['module_id'] . ':' . $p['link_field_name'];
                if ($this->recolectando) {
                    $this->recolectado[$sig] = $sig;
                    return null;
                }
                if (array_key_exists($sig, $this->traido)) {
                    $d = $this->traido[$sig];
                    unset($this->traido[$sig]);
                    return $d;
                }
                $this->calls[] = $sig;
                return $this->datos[$sig] ?? array();
            }
            public function getRecordsModule($m, $q = '', $f = array()) { $this->calls[] = 'records:' . $m; return array(); }
            public function getFieldDefinition($m, $f) { return (object) array('module_fields' => array()); }
        };
        if (!$paralelo) {
            return $base;
        }
        return new class($datos) {
            public $calls = array();
            public $batches = array();
            private $datos;
            private $recolectando = false;
            private $recolectado = array();
            private $traido = array();
            public function __construct($datos) { $this->datos = $datos; }
            public function getRelatedElementsForLoggedUser($p)
            {
                $sig = $p['module_name'] . ':' . $p['module_id'] . ':' . $p['link_field_name'];
                if ($this->recolectando) {
                    $this->recolectado[$sig] = $sig;
                    return null;
                }
                if (array_key_exists($sig, $this->traido)) {
                    $d = $this->traido[$sig];
                    unset($this->traido[$sig]);
                    return $d;
                }
                $this->calls[] = $sig;
                return $this->datos[$sig] ?? array();
            }
            public function getRecordsModule($m, $q = '', $f = array()) { $this->calls[] = 'records:' . $m; return array(); }
            public function getFieldDefinition($m, $f) { return (object) array('module_fields' => array()); }
            public function collectRequests(callable $fn)
            {
                $this->recolectando = true;
                $this->recolectado = array();
                try {
                    SugarRestApiCall::collect(function () use ($fn) { $fn(); });
                } finally {
                    $this->recolectando = false;
                }
                return array_values($this->recolectado);
            }
            public function callMany($requests)
            {
                $this->batches[] = count($requests);
                foreach ($requests as $sig) {
                    $this->calls[] = 'tanda:' . $sig;
                    $this->traido[$sig] = $this->datos[$sig] ?? array();
                }
                return count($requests);
            }
        };
    }

    public function test_con_tandas_sale_exactamente_lo_mismo_que_en_serie()
    {
        $serie = sticpa_gather_calendar_data($this->crm(false));
        $tandas = sticpa_gather_calendar_data($this->crm(true));
        $this->assertEquals($serie, $tandas);
    }

    public function test_los_datos_son_los_esperados()
    {
        $data = sticpa_gather_calendar_data($this->crm(true));
        $this->assertSame(array('s1', 's2', 's3'), array_column($data['sessions'], 'id'));
        // La cancelada (r3) no aporta su evento.
        $this->assertStringNotContainsString('e9', json_encode($data));
        // El respaldo por nombre: a2 no traía id de sesión y casó con «Sábado 2».
        $this->assertSame(array('s1' => 'yes', 's2' => 'no', 's3' => 'yes'), $data['attendance_by_session']);
    }

    public function test_cada_nivel_sale_en_una_tanda()
    {
        $crm = $this->crm(true);
        sticpa_gather_calendar_data($crm);

        // Tanda 1: eventos + asistencias de r1 y r2 (4). Tanda 2: sesiones de e1 y e2 (2).
        $this->assertSame(array(4, 2), $crm->batches);
        // En serie solo quedan la lista de inscripciones y los eventos de la ventana.
        $sueltas = array_values(array_filter($crm->calls, function ($c) { return strpos($c, 'tanda:') !== 0; }));
        $this->assertSame(array('Contacts:yo:stic_registrations_contacts', 'records:stic_Events'), $sueltas);
    }

    public function test_un_evento_repetido_ya_no_se_consulta_dos_veces()
    {
        $crm = $this->crm(false);
        sticpa_gather_calendar_data($crm);
        $this->assertSame(1, count(array_keys($crm->calls, 'stic_Events:e1:stic_sessions_stic_events')));
    }
}
