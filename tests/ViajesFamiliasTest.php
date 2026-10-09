<?php
use PHPUnit\Framework\TestCase;

if (!function_exists('get_date_from_gmt')) {
    function get_date_from_gmt($d, $f = 'Y-m-d H:i:s') { return date($f, strtotime($d . ' UTC')); }
}
if (!function_exists('plugins_url')) {
    function plugins_url($p = '', $f = '') { return '/wp-content/plugins/sinergiacrm-private-area/' . $p; }
}

/**
 * Un doble del CRM con los datos de una FAMILIA DE VERDAD: tres inscripciones
 * activas y una cancelada, sus eventos, pagos y compromisos.
 *
 * `FakeSCP` (el de Pasar Lista) devuelve vacío para inscripciones y pagos, así
 * que con él la ficha de un evento o Pagos salen sin la tanda del evento de
 * cada inscripción ni la de los pagos de cada compromiso: medían una familia
 * sin nada. Este doble modela lo mismo que FakeSCP (recolecta, tanda, memo de
 * un solo uso) y apunta la SECUENCIA de esperas, para contar viajes.
 */
class ViajesFamiliasFake
{
    public $session_id = 'sid';
    public $lastError = '';
    /** Secuencia: array('serie', etiqueta) o array('tanda', n, etiquetas). */
    public $log = array();
    private $recolectando = false;
    private $recolectado = array();
    private $traido = array();

    public function fila(array $campos)
    {
        $o = new stdClass();
        $o->id = $campos['id'] ?? '';
        $o->name_value_list = new stdClass();
        foreach ($campos as $k => $v) {
            $o->name_value_list->$k = (object) array('name' => $k, 'value' => $v);
        }
        return $o;
    }

    private function servir($firma, $etiqueta, callable $productor)
    {
        if ($this->recolectando) {
            $this->recolectado[$firma] = array('sig' => $firma, 'label' => $etiqueta, 'producer' => $productor);
            return null;
        }
        $this->lastError = '';
        if (array_key_exists($firma, $this->traido)) {
            $r = $this->traido[$firma];
            unset($this->traido[$firma]);
            return $r;
        }
        $this->log[] = array('serie', $etiqueta);
        return $productor();
    }

    public function collectRequests(callable $fn)
    {
        $this->recolectando = true;
        $this->recolectado = array();
        try {
            SugarRestApiCall::collect(function () use ($fn) { $fn(); });
        } finally {
            $this->recolectando = false;
        }
        $out = array_values($this->recolectado);
        $this->recolectado = array();
        return $out;
    }

    public function callMany($requests)
    {
        $etiquetas = array();
        foreach ($requests as $r) {
            $etiquetas[] = $r['label'];
            $this->traido[$r['sig']] = call_user_func($r['producer']);
        }
        $this->log[] = array('tanda', count($requests), $etiquetas);
        return count($requests);
    }

    public function getRecordDetail($id, $module, $fields = null)
    {
        $self = $this;
        return $this->servir('det|' . $module . '|' . $id . '|' . md5(serialize($fields)), 'getRecordDetail:' . $module, function () use ($self, $id) {
            $r = new stdClass();
            $r->entry_list = array($self->fila(array(
                'id' => $id, 'name' => 'Convivencia ' . $id, 'status' => 'open', 'type' => 'convivencia',
                'start_date' => '2026-11-14 10:00:00', 'end_date' => '2026-11-15 18:00:00',
                'assigned_user_id' => 'deleg-castellon', 'description' => '',
            )));
            return $r;
        });
    }

    public function getFieldDefinition($module, $fields = array())
    {
        return $this->servir('def|' . $module . '|' . md5(serialize($fields)), 'getFieldDefinition:' . $module, function () use ($fields) {
            $mf = array();
            foreach ((array) $fields as $f) {
                $mf[$f] = array('name' => $f, 'type' => 'varchar', 'label' => $f, 'options' => array());
            }
            $mf['status'] = array('name' => 'status', 'type' => 'enum', 'label' => 'Estado', 'options' => array(
                'open' => array('name' => 'open', 'value' => 'Abierto'),
                'confirmed' => array('name' => 'confirmed', 'value' => 'Confirmada'),
                'cancelled' => array('name' => 'cancelled', 'value' => 'Cancelada'),
            ));
            return json_decode(json_encode(array('module_fields' => $mf)));
        });
    }

    public function getRecordsModule($module, $query = '', $fields = array(), $rel = null)
    {
        $self = $this;
        return $this->servir('rec|' . $module . '|' . md5($query . serialize($fields)), 'getRecordsModule:' . $module, function () use ($self, $module) {
            if ($module !== 'stic_Events') {
                return array();
            }
            $out = array();
            for ($i = 1; $i <= 6; $i++) {
                $out[] = $self->fila(array('id' => 'e' . $i, 'name' => 'Evento ' . $i, 'type' => 'convivencia', 'status' => 'open',
                    'start_date' => '2026-11-1' . $i . ' 10:00:00', 'end_date' => '2026-11-1' . $i . ' 18:00:00',
                    'assigned_user_id' => 'deleg-castellon'));
            }
            return $out;
        });
    }

    public function getRelatedElementsForLoggedUser($p)
    {
        $self = $this;
        $clave = $p['module_name'] . ':' . $p['link_field_name'];
        return $this->servir('rel|' . md5(serialize($p)), $clave, function () use ($self, $p, $clave) {
            $id = (string) $p['module_id'];
            switch ($clave) {
                case 'Contacts:stic_registrations_contacts':
                    $out = array();
                    foreach (array('r1' => 'e1', 'r2' => 'e2', 'r3' => 'e3', 'r4' => 'e4') as $r => $e) {
                        $out[] = $self->fila(array('id' => $r, 'name' => 'Inscripción ' . $r,
                            'status' => $r === 'r4' ? 'cancelled' : 'confirmed', 'registration_date' => '2026-09-01'));
                    }
                    return $out;
                case 'stic_Registrations:stic_registrations_stic_events':
                    $e = 'e' . substr($id, 1);
                    return array($self->fila(array('id' => $e, 'name' => 'Evento ' . substr($e, 1),
                        'start_date' => '2026-11-14 10:00:00', 'end_date' => '2026-11-14 18:00:00')));
                case 'Contacts:stic_payment_commitments_contacts':
                    return array($self->fila(array('id' => 'pc1', 'name' => 'Convivencia', 'banking_concept' => 'Convivencia de otoño', 'amount' => '35',
                        'payment_method' => 'direct_debit', 'periodicity' => 'punctual', 'active' => '1')));
                case 'Contacts:stic_payments_contacts':
                    return array(
                        $self->fila(array('id' => 'p1', 'name' => 'Pago 1', 'status' => 'paid', 'amount' => '35', 'payment_date' => '2026-09-10', 'payment_method' => 'direct_debit')),
                        $self->fila(array('id' => 'p2', 'name' => 'Pago 2', 'status' => 'not_paid', 'amount' => '20', 'payment_date' => '2026-10-10', 'payment_method' => 'direct_debit')),
                    );
                case 'stic_Payment_Commitments:stic_payments_stic_payment_commitments':
                    // El mismo pago que ya trae la persona: con persona puesta,
                    // nada que arreglar (eso es otra escritura, no una lectura).
                    return array($self->fila(array('id' => 'p2', 'name' => 'Pago 2', 'status' => 'not_paid', 'amount' => '20', 'payment_date' => '2026-10-10', 'payment_method' => 'direct_debit')));
            }
            return array();
        });
    }

    public function set_entry($m, $d) { $this->log[] = array('serie', 'set_entry:' . $m); return 'nuevo'; }
    public function set_relationship($m, $id, $link, $ids) { $this->log[] = array('serie', 'set_relationship:' . $link); return true; }
}

/**
 * CUÁNTAS ESPERAS CUESTAN LAS PANTALLAS DE UNA FAMILIA (plan 042, VEL-4/5).
 *
 * Una espera es una llamada suelta o una tanda de hasta 4 (las de 5-8 son dos:
 * `callMany()` no tiene más de 4 en vuelo). ~350 ms cada una con keep-alive.
 * Los topes fijan la ganancia: si alguien vuelve a poner en fila lo que va en
 * tanda, sube y falla.
 */
class ViajesFamiliasTest extends TestCase
{
    /** @var ViajesFamiliasFake */
    private $scp;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../menu.php';
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-listController.php';
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/eventos-cuerpo.php';
        require_once __DIR__ . '/../inc/stic-event-web.php';
        require_once __DIR__ . '/../inc/stic-event-audience.php';
        require_once __DIR__ . '/../inc/stic-registrations.php';
        require_once __DIR__ . '/../inc/stic-payments.php';
        require_once __DIR__ . '/../inc/stic-documents.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_pl_now'] = mktime(17, 0, 0, 10, 9, 2026);
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_options'] = array();
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array(
            'scp_user_id' => 'u1',
            'scp_module' => 'Contacts',
            'scp_user_assigned_user_id' => 'deleg-castellon',
            'scp_user_contact_name' => 'Pérez, Lucía',
            'scp_user_adult' => true,
            'scp_role' => '',
            'scp_role_resolved' => true,
            'scp_relationship_raw' => '^grupo^',
        );
        $_REQUEST = array();
        $_GET = array();
        $_POST = array();
        sticpa_registration_memo_reset();
        SugarRestApiCall::forgetMemo();
        $this->scp = new ViajesFamiliasFake();
        SugarRestApiCall::$objSCP = $this->scp;
        // La definición de campos de eventos ya caliente: dura 6 h y es de todo
        // el sitio, así que es lo normal. Lo que está frío es lo de la persona
        // (el calendario, 5 min).
        sticpa_event_field_definition($this->scp);
        $this->scp->log = array();
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
    }

    private function render($page, array $req = array())
    {
        $_REQUEST = $req;
        $_GET = $req;
        $html = '';
        $objSCP = $this->scp;
        $pageSettings = array();
        ob_start();
        try {
            require __DIR__ . '/../pages/' . $page . '.php';
        } finally {
            ob_end_clean();
        }
        return $html;
    }

    /** [esperas, la secuencia legible]. */
    private function esperas()
    {
        $n = 0;
        $pasos = array();
        foreach ($this->scp->log as $e) {
            if ($e[0] === 'serie') {
                $n++;
                $pasos[] = $e[1];
            } else {
                $n += (int) ceil($e[1] / 4);
                $pasos[] = 'TANDA(' . implode(', ', $e[2]) . ')';
            }
        }
        return array($n, implode(' → ', $pasos));
    }

    /** VEL-5: el evento, tus inscripciones y sus documentos, juntos. Eran 4. */
    public function test_la_ficha_del_evento_con_el_calendario_frio_son_dos_esperas(): void
    {
        $html = $this->render('single_stic_events', array('id' => 'e5'));
        list($n, $pasos) = $this->esperas();
        $this->assertLessThanOrEqual(2, $n, "Ficha del evento: {$n} esperas: {$pasos}");
        $this->assertStringContainsString('Convivencia e5', $html);
    }

    /** VEL-5: lo que memoriza la recolecta no puede decir «no tienes plaza». */
    public function test_la_ficha_sabe_que_ya_tienes_plaza_aunque_vaya_en_tanda(): void
    {
        $this->render('single_stic_events', array('id' => 'e2'));
        $this->assertTrue(prefix_user_has_active_registration($this->scp, 'e2'));
        $this->assertFalse(prefix_user_has_active_registration($this->scp, 'e4'), 'r4 está cancelada');
    }

    /**
     * VEL-4: tus compromisos y tus pagos, juntos; después los pagos de cada
     * compromiso vivo, que sí dependen de lo primero. Eran 3 en cada visita
     * (Pagos no tiene caché, a propósito).
     */
    public function test_pagos_son_dos_esperas(): void
    {
        // La definición de los desplegables de Pagos, caliente (6 h, de todo el sitio).
        sticpa_cached_field_definition($this->scp, 'stic_Payments', array('status', 'payment_method', 'payment_type'));
        $this->scp->log = array();
        $html = $this->render('list_stic_payments');
        list($n, $pasos) = $this->esperas();
        $this->assertLessThanOrEqual(2, $n, "Pagos: {$n} esperas: {$pasos}");
        $this->assertStringContainsString('stic-rec-card', $html);
    }

    /** VEL-5: los eventos de la ventana y tus inscripciones, juntos. Eran 3. */
    public function test_eventos_con_el_calendario_frio_son_dos_esperas(): void
    {
        $html = $this->render('list_stic_events');
        list($n, $pasos) = $this->esperas();
        $this->assertLessThanOrEqual(2, $n, "Eventos: {$n} esperas: {$pasos}");
        $this->assertStringContainsString('Evento 6', $html);
    }
}
