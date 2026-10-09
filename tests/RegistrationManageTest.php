<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('StiRedirect')) {
    /** El handler «redirige»: se corta aquí, antes del `exit`, y se mira a dónde. */
    class StiRedirect extends Exception
    {
        public $url;
        public function __construct($url)
        {
            parent::__construct('redirect: ' . $url);
            $this->url = (string) $url;
        }
    }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($url, $status = 302)
    {
        throw new StiRedirect($url);
    }
}

/**
 * GESTIONAR LA INSCRIPCIÓN desde el área (TODO EV-2, EV-6 y EV-7, 25/09/2026).
 *
 * Aquí hay DINERO de por medio (el compromiso de pago), así que se prueba el
 * handler de verdad, no solo las piezas: con un CRM de mentira que apunta cada
 * escritura, y cortando en la redirección antes del `exit`. Lo que no puede
 * pasar nunca:
 *
 *   · dos compromisos para una inscripción (el CRM tiene su automatismo: la
 *     renovación cobró dos veces el 22/09/2026);
 *   · un compromiso con un medio de pago que no existe en el desplegable;
 *   · una respuesta que no estaba entre las opciones;
 *   · cancelar o cambiar fuera de plazo, o sin la firma del formulario;
 *   · mover una inscripción a otro evento al «editarla».
 */
class RegistrationManageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/stic-event-audience.php';
        require_once __DIR__ . '/../inc/eventos-cuerpo.php';
        require_once __DIR__ . '/../inc/stic-event-web.php';
        require_once __DIR__ . '/../inc/stic-registrations.php';
        require_once __DIR__ . '/../inc/stic-payments.php';
    }

    /** @var object */
    private $crm;

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_transients'] = array();
        $_SESSION = array(
            'scp_user_id' => 'c1',
            'scp_module' => 'Contacts',
            'scp_user_adult' => true,
            'scp_user_contact_name' => 'Lucía Pérez',
            'scp_user_assigned_user_id' => 'del-cs',
        );
        $_REQUEST = array();
        $_GET = array();
        $this->crm = $this->crm();
        SugarRestApiCall::$objSCP = $this->crm;
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
        $_REQUEST = array();
        $_SESSION = array();
    }

    private function nvl(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    private function evento(array $extra = array())
    {
        return array_merge(array(
            'id' => 'ev-1', 'name' => 'COM | Convivencia Inicial 2026', 'status' => 'registration',
            'start_date' => date('Y-m-d', strtotime('+20 days')), 'end_date' => date('Y-m-d', strtotime('+22 days')),
            'assigned_user_id' => 'del-cs', 'price' => '60.00',
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('+10 days')),
            'ajmcm_pregunta_1_c' => '¿Cómo vienes? | Sí, voy en autobús; No, voy por mi cuenta',
        ), $extra);
    }

    /** Un CRM que apunta lo que se escribe y contesta lo que se le prepara. */
    private function crm()
    {
        $t = $this;
        return new class($t) {
            public $writes = array();
            public $relations = array();
            public $events = array();
            public $registrations = array();
            public $commitmentsOfReg = array();
            public $myRegs = array();
            public $statusOptions = array('confirmed' => 'Confirmada', 'cancelled' => 'Cancelada');
            public $methodOptions = array('direct_debit' => 'Domiciliación', 'card' => 'Tarjeta', 'bizum' => 'Bizum', 'stripe' => 'Stripe');
            public $answerFieldsExist = true;
            public $contacts = array();
            public $commitmentsByDescription = array(); // id => description
            public $paymentsOfCommitment = array();     // id => [status, …]
            public $queries = array();
            public $payments = array();          // id => campos (getRecordDetail de stic_Payments)
            public $commitments = array();       // id => campos (getRecordDetail de stic_Payment_Commitments)
            public $regsOfCommitment = array();  // compromiso => [inscripción, …]
            public $myPayments = array();        // pagos de quien está en sesión
            public $commitmentMeta = array();    // id => campos extra en getRecordsModule
            public $autoPayments = false;        // como el CRM: al crear un compromiso, su pago pendiente
            // COMO LA API v4.1 DE VERDAD (02/10/2026): el campo plano
            // `stic_paymebfe2itments_ida` de un pago llega VACÍO; el compromiso
            // solo se sabe por la relación. El doble que lo daba mentía.
            public $flatPaymentLink = false;
            public $paymentStatusOptions = array('paid' => 'Pagado', 'pending' => 'Pendiente', 'not_remitted' => 'No remesado', 'cancelled' => 'Anulado');
            private $n = 0;
            private $t;
            public function __construct($t) { $this->t = $t; }
            private function nvl(array $f) { $o = new stdClass(); foreach ($f as $k => $v) { $o->$k = (object) array('value' => $v); } return $o; }
            private function opts(array $o) { $out = array(); foreach ($o as $k => $v) { $out[$k] = array('name' => $k, 'value' => $v); } return $out; }
            public function getFieldDefinition($module, $fields)
            {
                $def = array();
                foreach ($fields as $f) {
                    if ($module === 'stic_Registrations' && strpos($f, 'ajmcm_respuesta_') === 0 && !$this->answerFieldsExist) {
                        continue;
                    }
                    $def[$f] = array('name' => $f);
                }
                if (isset($def['status']) && $module === 'stic_Registrations') {
                    $def['status']['options'] = $this->opts($this->statusOptions);
                }
                if (isset($def['payment_method'])) {
                    $def['payment_method']['options'] = $this->opts($this->methodOptions);
                }
                if (isset($def['payment_type'])) {
                    $def['payment_type']['options'] = $this->opts(array('donation' => 'Donativo', 'services' => 'Servicios', 'fee' => 'Cuota'));
                }
                if (isset($def['status']) && $module === 'stic_Payments') {
                    $def['status']['options'] = $this->opts($this->paymentStatusOptions);
                }
                return (object) array('module_fields' => $def);
            }
            public function getRecordDetail($id, $module, $fields = null)
            {
                $src = $module === 'stic_Events' ? $this->events : ($module === 'stic_Registrations' ? $this->registrations : ($module === 'Contacts' ? $this->contacts : array()));
                if ($module === 'stic_Payments') {
                    $src = $this->payments;
                    if (!$this->flatPaymentLink) {
                        foreach ($src as $k => $v) {
                            unset($src[$k]['stic_paymebfe2itments_ida']);
                        }
                    }
                }
                if ($module === 'stic_Payment_Commitments') {
                    $src = $this->commitments;
                    foreach ($this->commitmentsByDescription as $cid => $desc) {
                        $src[$cid] = array_merge(array('id' => $cid, 'description' => $desc), $src[$cid] ?? array());
                    }
                }
                if (!isset($src[$id])) {
                    return (object) array('entry_list' => array());
                }
                return (object) array('entry_list' => array((object) array('id' => $id, 'name_value_list' => $this->nvl($src[$id]))));
            }
            public function getRelatedElementsForLoggedUser($p)
            {
                $link = $p['link_field_name'] ?? '';
                if ($link === 'stic_registrations_contacts') {
                    $out = array();
                    foreach ($this->myRegs as $id => $status) {
                        $out[] = (object) array('id' => $id, 'name_value_list' => $this->nvl(array('id' => $id, 'status' => $status)));
                    }
                    return $out;
                }
                if ($link === 'stic_payments_contacts') {
                    $out = array();
                    foreach ($this->myPayments as $id) {
                        $out[] = (object) array('id' => $id, 'name_value_list' => $this->nvl(array('id' => $id)));
                    }
                    return $out;
                }
                if ($link === 'stic_payment_commitments_stic_registrations' && ($p['module_name'] ?? '') === 'stic_Payment_Commitments') {
                    $out = array();
                    foreach ($this->regsOfCommitment[$p['module_id']] ?? array() as $rid) {
                        $out[] = (object) array('id' => $rid, 'name_value_list' => $this->nvl(array_merge(array('id' => $rid), $this->registrations[$rid] ?? array())));
                    }
                    return $out;
                }
                if ($link === 'stic_payment_commitments_stic_registrations') {
                    $out = array();
                    foreach ($this->commitmentsOfReg[$p['module_id']] ?? array() as $c) {
                        $out[] = (object) array('id' => $c['id'], 'name_value_list' => $this->nvl($c));
                    }
                    return $out;
                }
                if ($link === 'stic_payments_stic_payment_commitments' && ($p['module_name'] ?? '') === 'stic_Payments') {
                    $cid = $this->payments[$p['module_id']]['stic_paymebfe2itments_ida'] ?? '';
                    return $cid !== '' ? array((object) array('id' => $cid, 'name_value_list' => $this->nvl(array('id' => $cid)))) : array();
                }
                if ($link === 'stic_payments_stic_payment_commitments') {
                    $out = array();
                    foreach ($this->paymentsOfCommitment[$p['module_id']] ?? array() as $i => $status) {
                        // Un estado suelto, o los campos del pago.
                        $f = is_array($status) ? $status : array('status' => $status);
                        $f = array_merge(array('id' => 'pay-' . $i,
                            'name' => 'David Soler Balado - Donativo - 110,00 - 2026-09-30', 'payment_type' => 'donation'), $f);
                        $out[] = (object) array('id' => $f['id'], 'name_value_list' => $this->nvl($f));
                    }
                    return $out;
                }
                if ($link === 'stic_registrations_stic_events') {
                    $ev = $this->registrations[$p['module_id']]['stic_registrations_stic_eventsstic_events_ida'] ?? '';
                    return $ev !== '' ? array((object) array('id' => $ev)) : array();
                }
                return array();
            }
            public function getRecordsModule($module, $query = '', $fields = array(), $rel = null)
            {
                $this->queries[] = array($module, $query);
                $out = array();
                if ($module === 'stic_Payment_Commitments'
                    && preg_match("/description LIKE '%(.*)%'$/", $query, $m)) {
                    foreach ($this->commitmentsByDescription as $id => $desc) {
                        if (strpos($desc, $m[1]) !== false) {
                            $out[] = (object) array('id' => $id, 'name_value_list' => $this->nvl(array_merge(array('id' => $id,
                                'name' => 'David Soler Balado - Donativo - 110,00', 'payment_type' => 'donation',
                                'date_entered' => gmdate('Y-m-d H:i:s'), 'description' => $desc), $this->commitmentMeta[$id] ?? array())));
                        }
                    }
                }
                return $out;
            }
            public function set_entry($module, $data)
            {
                $id = $data['id'] ?? ($module . '-new-' . (++$this->n));
                $this->writes[] = array('module' => $module, 'data' => $data, 'id' => $id);
                if ($this->autoPayments && $module === 'stic_Payment_Commitments' && empty($data['id'])) {
                    $this->paymentsOfCommitment[$id] = array(array('id' => 'pay-de-' . $id, 'status' => 'pending'));
                }
                return $id;
            }
            public function set_relationship($module, $id, $link, $ids = array())
            {
                $this->relations[] = array($module, $id, $link, $ids);
                return true;
            }
            public function writesTo($module)
            {
                return array_values(array_filter($this->writes, function ($w) use ($module) { return $w['module'] === $module; }));
            }
        };
    }

    /** Lanza el handler con un formulario firmado y devuelve a dónde redirige. */
    private function post(array $fields, $firma = true)
    {
        $_REQUEST = $fields;
        if ($firma) {
            $names = array_values(array_diff(array_keys($fields), array('stic-action', 'action', 'scp_current_url')));
            $_REQUEST['stic_form_fields'] = sticpa_form_token('single_stic_registrations', $names);
        }
        $_REQUEST['scp_current_url'] = '/ap/?internalpage=single_stic_registrations';
        try {
            prefix_admin_single_stic_registrations();
        } catch (StiRedirect $r) {
            return $r->url;
        }
        $this->fail('el handler no redirigió');
    }

    private function inscribirse(array $extra = array())
    {
        return $this->post(array_merge(array(
            'stic-action' => 'create',
            'id' => '',
            'stic_registrations_stic_eventsstic_events_ida' => 'ev-1',
            'status' => 'confirmed',
            'special_needs' => '0',
            'ajmcm_respuesta_1_c' => '1',
            'sticpa_pago_metodo' => 'bizum',
        ), $extra));
    }

    /* ---- Las preguntas (EV-6) ---------------------------------------- */

    public function test_una_pregunta_se_lee_con_o_sin_texto_de_pregunta()
    {
        $this->assertSame(array('pregunta' => '', 'opciones' => array('Sí, voy en autobús', 'No, voy por mi cuenta')),
            sticpa_event_question_parse('Sí, voy en autobús;No, voy por mi cuenta'));
        $this->assertSame(array('pregunta' => '¿Cómo vienes?', 'opciones' => array('En autobús', 'Por mi cuenta')),
            sticpa_event_question_parse(' ¿Cómo vienes? |  En autobús ;; Por mi cuenta; En autobús '));
        $this->assertSame('«Comida» incluida', sticpa_event_question_parse('Sí; &quot;No&quot;;&laquo;Comida&raquo; incluida')['opciones'][2],
            'las entidades con las que guarda SuiteCRM se deshacen');
        $this->assertNull(sticpa_event_question_parse('Solo una opción'), 'una sola opción no es una pregunta');
        $this->assertNull(sticpa_event_question_parse(''));
    }

    public function test_sin_el_campo_de_respuesta_en_el_crm_no_hay_pregunta()
    {
        $nvl = $this->nvl($this->evento());
        $this->assertCount(1, sticpa_event_questions($nvl, array('ajmcm_respuesta_1_c' => array())));
        $this->assertCount(0, sticpa_event_questions($nvl, array()), 'hasta que se cree el campo, nada');
    }

    public function test_la_respuesta_viaja_como_numero_y_se_guarda_el_texto()
    {
        $q = sticpa_event_questions($this->nvl($this->evento()), array('ajmcm_respuesta_1_c' => array()));
        $data = array('ajmcm_respuesta_1_c' => '2');
        $this->assertTrue(sticpa_event_questions_resolve($q, $data));
        $this->assertSame('No, voy por mi cuenta', $data['ajmcm_respuesta_1_c']);
        foreach (array('0', '3', 'Sí, voy en autobús', '') as $malo) {
            $data = array('ajmcm_respuesta_1_c' => $malo);
            $this->assertFalse(sticpa_event_questions_resolve($q, $data), "«{$malo}» no es una opción");
        }
    }

    public function test_al_editar_se_marca_lo_que_ya_se_contesto()
    {
        $q = sticpa_event_questions($this->nvl($this->evento()), array('ajmcm_respuesta_1_c' => array()));
        $f = sticpa_event_question_form_fields($q, $this->nvl(array('ajmcm_respuesta_1_c' => 'No, voy por mi cuenta')));
        $this->assertSame(array('ajmcm_respuesta_1_c'), $f[0]['posts'], 'sin declararla, la respuesta no se guardaría');
        $this->assertSame(array('ajmcm_respuesta_1_c'), sticpa_form_posted_fields($f));
        $html = $f[0]['html'];
        $this->assertStringContainsString('¿Cómo vienes?', $html);
        $this->assertStringContainsString("value='1' required>", $html);
        $this->assertStringContainsString("value='2' required checked>", $html, 'lo que ya contestó, marcado');
        $this->assertStringContainsString('Sí, voy en autobús', $html);
    }

    /* ---- El pago (EV-7) ---------------------------------------------- */

    public function test_iban()
    {
        $this->assertTrue(sticpa_iban_is_valid('ES91 2100 0418 4502 0005 1332'));
        $this->assertTrue(sticpa_iban_is_valid('es9121000418450200051332'));
        $this->assertFalse(sticpa_iban_is_valid('ES9121000418450200051333'), 'dígito de control mal');
        $this->assertFalse(sticpa_iban_is_valid('ES912100041845020005133'), 'un español tiene 24');
        $this->assertFalse(sticpa_iban_is_valid(''));
    }

    public function test_solo_se_ofrecen_los_medios_que_existen_en_el_crm()
    {
        $m = sticpa_registration_payment_methods($this->crm);
        $this->assertSame(array('bizum' => 'Bizum', 'direct_debit' => 'Domiciliación', 'card' => 'Tarjeta'), $m,
            'ni `transfer` (el CRM no lo tiene) ni `stripe` (no es candidato)');
    }

    public function test_inscribirse_a_algo_con_precio_crea_UN_compromiso_atado_y_de_su_delegacion()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->inscribirse();

        $regs = $this->crm->writesTo('stic_Registrations');
        $this->assertCount(1, $regs);
        $reg = $regs[0];
        $this->assertSame('del-cs', $reg['data']['assigned_user_id'], 'la inscripción, a su delegación');
        $this->assertSame('Sí, voy en autobús', $reg['data']['ajmcm_respuesta_1_c']);
        $this->assertArrayNotHasKey('sticpa_pago_metodo', $reg['data'], 'la elección de pago no es un campo del CRM');
        $this->assertArrayNotHasKey('ajmcm_registration_amount_c', $reg['data'],
            'sin importe en la inscripción: es lo que dispara el automatismo del CRM');

        $coms = $this->crm->writesTo('stic_Payment_Commitments');
        $this->assertCount(1, $coms, 'uno, y solo uno');
        $c = $coms[0]['data'];
        // SIN «.00»: el CRM lee «60.00» como 6.000 (coma decimal en el
        // usuario técnico). Pasó con el Congreso: 35 € guardados como 3.500.
        $this->assertSame('60', $c['amount']);
        $this->assertSame('bizum', $c['payment_method']);
        $this->assertSame('services', $c['payment_type']);
        $this->assertSame('punctual', $c['periodicity']);
        $this->assertSame('del-cs', $c['assigned_user_id']);
        $this->assertSame(date('Y-m-d'), $c['first_payment_date']);
        $this->assertArrayNotHasKey('bank_account', $c);

        $links = array_map(function ($r) { return $r[2] . ':' . implode(',', $r[3]); }, $this->crm->relations);
        $this->assertContains('stic_payment_commitments_contacts:c1', $links, 'quien paga');
        $this->assertContains('stic_payment_commitments_stic_registrations:' . $reg['id'], $links, 'y su inscripción');
        $this->assertStringContainsString('action=detail&id=' . $reg['id'], $url);
        $this->assertStringContainsString('msg=inscrita_pago', $url);
        // Y el formulario del pago: los medios como tarjetas, el precio al lado y nada de sermón.
        $campos = sticpa_registration_payment_form_fields(60.0, sticpa_registration_payment_methods($this->crm));
        $this->assertSame(array('sticpa_pago_metodo', 'sticpa_pago_iban'), sticpa_form_posted_fields($campos));
        $this->assertStringContainsString("<span class='stic-choice-price'>", $campos[0]['html']);
        $this->assertStringNotContainsString('cuesta', $campos[0]['html']);
    }

    public function test_si_el_crm_ya_creo_el_compromiso_se_completa_ese_y_no_se_crea_otro()
    {
        $this->crm->events['ev-1'] = $this->evento();
        // El automatismo del CRM ya colgó uno de la inscripción recién creada.
        $this->crm->commitmentsOfReg['stic_Registrations-new-1'] = array(array('id' => 'com-crm'));
        $this->inscribirse();
        $coms = $this->crm->writesTo('stic_Payment_Commitments');
        $this->assertCount(1, $coms);
        $this->assertSame('com-crm', $coms[0]['data']['id'], 'se completa el del CRM');
        $this->assertSame('bizum', $coms[0]['data']['payment_method']);
        $this->assertSame(array(), $this->crm->relations, 'ya estaba atado: no se toca');
    }

    public function test_un_familiar_paga_y_el_participante_es_el_destinatario()
    {
        $_SESSION['scp_user_adult'] = false;
        $_SESSION['scp_tutor_user_id'] = 'madre-1';
        $this->crm->events['ev-1'] = $this->evento();
        $this->inscribirse();
        $links = array_map(function ($r) { return $r[2] . ':' . implode(',', $r[3]); }, $this->crm->relations);
        $this->assertContains('stic_payment_commitments_contacts:madre-1', $links);
        $this->assertContains('stic_payment_commitments_contacts_1:c1', $links);
    }

    public function test_domiciliacion_exige_un_iban_valido_y_lo_guarda_en_el_compromiso()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->inscribirse(array('sticpa_pago_metodo' => 'direct_debit', 'sticpa_pago_iban' => 'ES91 2100 0418 4502 0005 1333'));
        $this->assertStringContainsString('msg=error_pago', $url);
        $this->assertSame(array(), $this->crm->writes, 'con un IBAN malo no se escribe NADA');

        $this->inscribirse(array('sticpa_pago_metodo' => 'direct_debit', 'sticpa_pago_iban' => 'es91 2100 0418 4502 0005 1332'));
        $c = $this->crm->writesTo('stic_Payment_Commitments')[0]['data'];
        $this->assertSame('direct_debit', $c['payment_method']);
        $this->assertSame('ES9121000418450200051332', $c['bank_account']);
    }

    public function test_un_medio_que_no_existe_en_el_crm_no_se_acepta()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->inscribirse(array('sticpa_pago_metodo' => 'transfer'));
        $this->assertStringContainsString('msg=error_pago', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    /**
     * CON TARJETA TAMBIÉN SE CREA EL COMPROMISO (plan 041): lo que se debe se
     * ve aunque el pago no se termine, y se va a pagar SU pago pendiente. El
     * pago con tarjeta lo sustituirá a la vuelta: un solo compromiso vivo.
     */
    public function test_con_tarjeta_se_crea_su_compromiso_y_se_va_a_pagar_su_pago_pendiente()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->crm->autoPayments = true;
        $url = $this->inscribirse(array('sticpa_pago_metodo' => 'card'));
        $pcs = $this->crm->writesTo('stic_Payment_Commitments');
        $this->assertCount(1, $pcs);
        $this->assertSame('card', $pcs[0]['data']['payment_method']);
        $this->assertSame('services', $pcs[0]['data']['payment_type']);
        $this->assertStringContainsString('internalpage=single_stic_payment_form', $url);
        $this->assertStringContainsString('paymentId=pay-de-' . $pcs[0]['id'], $url);
    }

    /**
     * EL PAGO QUE GENERA EL CRM QUEDA A NOMBRE DE QUIEN PAGA. El CRM lo crea al
     * guardar el compromiso, antes de que tenga persona, y sin esto no salía
     * en Pagos (el de David en el Congreso, 02/10/2026).
     */
    public function test_el_pago_generado_se_pone_a_nombre_de_quien_paga()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->crm->autoPayments = true;
        $this->inscribirse(array('sticpa_pago_metodo' => 'bizum'));
        $pc = $this->crm->writesTo('stic_Payment_Commitments')[0]['id'];
        $links = array_map(function ($r) { return $r[0] . ':' . $r[1] . ':' . $r[2] . ':' . implode(',', $r[3]); }, $this->crm->relations);
        $this->assertContains('stic_Payments:pay-de-' . $pc . ':stic_payments_contacts:c1', $links);
    }

    public function test_un_importe_se_escribe_como_lo_lee_el_crm()
    {
        $this->assertSame('35', sticpa_crm_amount(35.0));
        $this->assertSame('35', sticpa_crm_amount('35.00'));
        $this->assertSame('35,50', sticpa_crm_amount(35.5));
        $this->assertSame('1200', sticpa_crm_amount(1200), 'sin separador de miles: el punto lo leería como decimal en otro CRM');
        $this->assertSame('0,99', sticpa_crm_amount(0.99));
    }

    /** Si el CRM no hubiera generado el pago, se paga por la inscripción, como antes. */
    public function test_con_tarjeta_y_sin_pago_generado_se_paga_por_la_inscripcion()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->inscribirse(array('sticpa_pago_metodo' => 'card'));
        $this->assertStringContainsString('registrationId=', $url);
        $this->assertStringContainsString('eventId=ev-1', $url);
    }

    /**
     * EL DINERO DE UN EVENTO ES DE QUIEN LO ORGANIZA (plan 041, D-1): un
     * evento nacional (de ECE) no se parte entre las delegaciones de cada uno.
     */
    public function test_la_inscripcion_y_su_compromiso_son_del_organizador_del_evento()
    {
        $this->crm->events['ev-1'] = $this->evento(array('assigned_user_id' => 'ece', 'ajmcm_ambito_c' => 'nacional'));
        $this->inscribirse(array('sticpa_pago_metodo' => 'bizum'));
        $this->assertSame('ece', $this->crm->writesTo('stic_Registrations')[0]['data']['assigned_user_id']);
        $this->assertSame('ece', $this->crm->writesTo('stic_Payment_Commitments')[0]['data']['assigned_user_id']);
    }

    /* ---- El formulario web avanzado (EV-3) ---------------------------- */

    private const FWA = 'https://crm.example.test/index.php?entryPoint=stic_AWF_renderForm&amp;id=f-1';
    /** La puerta solo acepta ids con forma de id del CRM. */
    private const EV = '00000ff6-facb-3ed5-0a30-6abe610c4cc0';

    /** Lanza la puerta del área al FWA y devuelve a dónde manda. */
    private function puertaFwa($eventId)
    {
        $_GET = array('e' => $eventId);
        try {
            sticpa_event_fwa_endpoint();
        } catch (StiRedirect $r) {
            return $r->url;
        }
        $this->fail('la puerta no redirigió');
    }

    public function test_el_enlace_del_fwa_llega_limpio_y_seguro()
    {
        $this->assertSame('https://crm.example.test/index.php?entryPoint=stic_AWF_renderForm&id=f-1', sticpa_event_fwa_url(self::FWA),
            'el CRM lo devuelve con &amp; y así el FWA no sabe qué formulario pintar');
        $this->assertSame('', sticpa_event_fwa_url('javascript:alert(1)'));
        $this->assertSame('', sticpa_event_fwa_url(''));
    }

    public function test_el_fwa_sale_relleno_sin_tocar_lo_que_ya_trae_el_enlace()
    {
        $url = sticpa_event_fwa_prefilled_url('https://crm.example.test/index.php?entryPoint=stic_AWF_renderForm&id=f-1#arriba', array(
            'first_name' => 'Lucía', 'last_name' => 'Pérez Gil', 'email1' => 'lucia+mcm@example.test', 'id' => 'otro', 'stic_identification_number_c' => '',
        ));
        $this->assertStringStartsWith('https://crm.example.test/index.php?entryPoint=stic_AWF_renderForm&id=f-1&', $url);
        $this->assertStringEndsWith('#arriba', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('f-1', $q['id'], 'un dato de la persona no cambia de formulario');
        $this->assertSame('Lucía', $q['first_name']);
        $this->assertSame('Pérez Gil', $q['last_name']);
        $this->assertSame('lucia+mcm@example.test', $q['email1']);
        $this->assertArrayNotHasKey('stic_identification_number_c', $q, 'lo vacío no viaja');
    }

    public function test_con_fwa_inscribirme_lleva_al_formulario_y_no_al_alta_corta()
    {
        $con = sticpa_event_view_model($this->nvl($this->evento(array('ajmcm_fwa_url_c' => self::FWA))));
        $sin = sticpa_event_view_model($this->nvl($this->evento()));
        $this->assertStringContainsString('action=sticpa_evento_fwa&e=ev-1', sticpa_event_signup_url($con));
        $this->assertStringContainsString('internalpage=single_stic_registrations&action=create', sticpa_event_signup_url($sin));

        $card = sticpa_events_cards(array($con))[0];
        $this->assertStringContainsString('action=sticpa_evento_fwa', end($card['actions'])['url']);
        $ficha = sticpa_event_detail_html($con);
        $this->assertStringContainsString('action=sticpa_evento_fwa', $ficha);
        $this->assertStringContainsString('formulario de la actividad', $ficha, 'se avisa de que se sale del área');
    }

    public function test_la_puerta_manda_al_fwa_con_tus_datos()
    {
        $this->crm->events[self::EV] = $this->evento(array('id' => self::EV, 'ajmcm_fwa_url_c' => self::FWA));
        $this->crm->contacts['c1'] = array('id' => 'c1', 'first_name' => 'Lucía', 'last_name' => 'Pérez', 'email1' => 'lucia@example.test',
            'stic_identification_number_c' => '12345678Z');
        $url = $this->puertaFwa(self::EV);
        $this->assertStringStartsWith('https://crm.example.test/index.php?entryPoint=stic_AWF_renderForm&id=f-1&', $url);
        $this->assertStringContainsString('email1=lucia%40example.test', $url);
        $this->assertStringContainsString('stic_identification_number_c=12345678Z', $url);
        $this->assertSame(array(), $this->crm->writes, 'la puerta no escribe nada en el CRM');
    }

    public function test_la_puerta_no_abre_el_fwa_si_no_toca()
    {
        // Ya inscrita: a la pantalla del área, que lo explica.
        $this->crm->events[self::EV] = $this->evento(array('id' => self::EV, 'ajmcm_fwa_url_c' => self::FWA));
        $this->crm->myRegs['reg-9'] = 'confirmed';
        $this->crm->registrations['reg-9'] = array('id' => 'reg-9', 'stic_registrations_stic_eventsstic_events_ida' => self::EV);
        $this->assertStringContainsString('internalpage=single_stic_registrations&action=create&from=stic_events&id=' . self::EV, $this->puertaFwa(self::EV));

        // Plazo cerrado.
        $this->crm->myRegs = array();
        $this->crm->events[self::EV] = $this->evento(array('id' => self::EV, 'ajmcm_fwa_url_c' => self::FWA, 'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('-2 days'))));
        $this->assertStringContainsString('internalpage=single_stic_registrations&action=create', $this->puertaFwa(self::EV));

        // Sin FWA, y un id que no es un id.
        $this->crm->events[self::EV] = $this->evento(array('id' => self::EV));
        $this->assertStringContainsString('internalpage=single_stic_registrations&action=create', $this->puertaFwa(self::EV));
        $this->assertStringContainsString('internalpage=list_stic_events', $this->puertaFwa('../../x'));
    }

    public function test_con_fwa_el_alta_corta_no_crea_nada()
    {
        $this->crm->events['ev-1'] = $this->evento(array('ajmcm_fwa_url_c' => self::FWA));
        $url = $this->inscribirse();
        $this->assertSame(array(), $this->crm->writesTo('stic_Registrations'));
        $this->assertSame(array(), $this->crm->writesTo('stic_Payment_Commitments'));
        $this->assertStringContainsString('action=create&from=stic_events&id=ev-1', $url);
    }

    /* ---- Pagos que no tienen a nadie --------------------------------- */

    public function test_pagos_ensena_y_arregla_lo_que_se_debe_sin_persona()
    {
        $row = function ($id, array $f) {
            $o = new stdClass();
            $o->id = $id;
            $o->name_value_list = $this->nvl(array_merge(array('id' => $id), $f));
            return $o;
        };
        $compromisos = array(
            $row('pc-congreso', array('description' => 'Creado desde el área privada al inscribirse', 'end_date' => '', 'payment_method' => 'bizum')),
            $row('pc-visto', array('description' => '', 'end_date' => '')),
            $row('pc-cerrado', array('description' => '', 'end_date' => '2026-09-01')),
            $row('pc-intento', array('description' => '[pago:00000fc4-a82c-13b3-7646-6abfc100354e:0123456789abcdef]', 'end_date' => '')),
        );
        $this->crm->paymentsOfCommitment['pc-congreso'] = array(array('id' => 'pay-congreso', 'status' => 'pending'));
        $this->crm->paymentsOfCommitment['pc-cerrado'] = array(array('id' => 'pay-cerrado', 'status' => 'cancelled'));
        $this->crm->paymentsOfCommitment['pc-intento'] = array(array('id' => 'pay-intento', 'status' => 'pending'));

        $this->crm->paymentsOfCommitment['pc-visto'] = array(array('id' => 'pay-visto', 'status' => 'pending'));
        // Como la API: sin el compromiso en el campo plano, y uno repetido.
        $yaSalen = array($row('pay-visto', array()), $row('pay-visto', array()));

        $res = sticpa_payments_complete($this->crm, $compromisos, $yaSalen, sticpa_payment_list_fields(), 'c1', 'stic_payments_contacts');
        $ids = array_map(function ($r) { return $r->id; }, $res['payments']);
        $this->assertSame(array('pay-visto', 'pay-congreso'), $ids,
            'sin repetidos, y lo que se debe sin persona; ni lo cerrado ni los intentos de tarjeta');
        $this->assertSame('pc-visto', $res['payments'][0]->name_value_list->stic_paymebfe2itments_ida->value, 'cada pago sabe de qué compromiso es');
        $this->assertContains(array('stic_Payments', 'pay-congreso', 'stic_payments_contacts', array('c1')), $this->crm->relations,
            'y se le pone la persona, para que la próxima vez salga solo');
    }

    /** Un compromiso del FWA sin concepto: en Pagos se llama como el evento, y se queda así en el CRM. */
    public function test_pagos_pone_nombre_a_lo_que_se_llamaba_tarjeta_via_redsys()
    {
        $fila = (object) array('id' => 'pc-fwa', 'name_value_list' => $this->nvl(array('id' => 'pc-fwa', 'end_date' => '',
            'payment_method' => 'card', 'description' => '', 'banking_concept' => 'Tarjeta (vía Redsys)')));
        $this->crm->paymentsOfCommitment['pc-fwa'] = array(array('id' => 'pay-fwa', 'status' => 'pending', 'banking_concept' => ''));
        $this->crm->regsOfCommitment['pc-fwa'] = array('reg-fwa');
        $this->crm->registrations['reg-fwa'] = array('name' => 'David Soler Balado - LC | Foro de Laicos Consolación 2026');
        $pago = (object) array('id' => 'pay-fwa', 'name_value_list' => $this->nvl(array('id' => 'pay-fwa',
            'name' => 'David Soler Balado - Tarjeta (vía Redsys) - 110 - 2026-10-02', 'amount' => '110.00', 'status' => 'pending',
            'payment_method' => 'card', 'payment_date' => '2026-10-02')));

        $res = sticpa_payments_complete($this->crm, array($fila), array($pago), sticpa_payment_list_fields(), 'c1', 'stic_payments_contacts');
        $this->assertSame('LC | Foro de Laicos Consolación 2026', $res['map']['pc-fwa']['banking_concept']);
        $datos = array_column($this->crm->writes, 'data');
        $this->assertContains(array('id' => 'pc-fwa', 'banking_concept' => 'LC | Foro de Laicos Consolación 2026'), $datos);
        $this->assertContains(array('id' => 'pay-fwa', 'banking_concept' => 'LC | Foro de Laicos Consolación 2026'), $datos);
        $html = sticpa_payments_list_html($res['payments'], array(), $res['map']);
        $this->assertStringContainsString('Foro de Laicos', $html);
        $this->assertStringNotContainsString('Tarjeta (vía Redsys)</', $html);
    }

    /* ---- Pagar con tarjeta (EV-9) ------------------------------------ */

    /** Pinta el formulario de pago con tarjeta y devuelve su HTML. */
    private function formularioDePago(array $request)
    {
        $this->crm->contacts['c1'] = array('id' => 'c1', 'email1' => 'lucia@example.test', 'first_name' => 'Lucía',
            'last_name' => 'Pérez', 'stic_identification_number_c' => '12345678Z');
        $_REQUEST = $request;
        $html = '';
        include __DIR__ . '/../pages/single_stic_payment_form.php';
        return $html;
    }

    private function hidden($html, $name)
    {
        return preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m)
            ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    }

    public function test_pagar_una_inscripcion_con_tarjeta_NO_es_una_donacion_y_es_de_la_delegacion()
    {
        $reg = '00000900-db01-acfe-2649-6ab30955f412';
        $this->crm->events['ev-1'] = $this->evento(array('price' => '110.00'));
        $this->crm->myRegs[$reg] = 'confirmed';
        $GLOBALS['__stic_options']['sticpa_scp_area_url'] = 'https://example.test/ap/';
        $html = $this->formularioDePago(array('eventId' => 'ev-1', 'registrationId' => $reg, 'amount' => '5'));
        unset($GLOBALS['__stic_options']['sticpa_scp_area_url']);
        // Vuelve al ÁREA (/ap/), no a la raíz de la web.
        $this->assertStringStartsWith('https://example.test/ap/?internalpage=', (string) $this->hidden($html, 'redirect_url'));
        $this->assertStringStartsWith('https://example.test/ap/?internalpage=single_stic_payment_error', (string) $this->hidden($html, 'redirect_ko_url'));

        $this->assertSame('services', $this->hidden($html, 'stic_Payment_Commitments___payment_type'));
        $this->assertSame('del-cs', $this->hidden($html, 'assigned_user_id'));
        // El importe es el precio del evento, no el de la URL, y no se toca.
        $this->assertSame('110.00', $this->hidden($html, 'stic_Payment_Commitments___amount'));
        $this->assertMatchesRegularExpression('/type="hidden"[^>]*name="stic_Payment_Commitments___amount"/', $html);
        // Solo tarjeta: los demás medios se eligen al inscribirse.
        $this->assertSame('card', $this->hidden($html, 'stic_Payment_Commitments___payment_method'));
        $this->assertStringNotContainsString('direct_debit', $html);
        // Nada que editar: ni datos de quien paga ni desplegables. El único
        // campo visible es «Pagar otra cantidad», que va sin `name` (lo copia
        // el script al oculto) y empieza escondido.
        $this->assertDoesNotMatchRegularExpression('/<input(?![^>]*type="hidden")[^>]*\bname=/', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertMatchesRegularExpression('/id="stic-pay-otro"[^>]*hidden/', $html);
        // Lleva la marca firmada y vuelve a la ficha de la inscripción.
        $this->assertStringContainsString(sticpa_registration_card_marker($reg), (string) $this->hidden($html, 'stic_Payment_Commitments___description'));
        $this->assertStringContainsString('single_stic_registrations&action=detail&id=' . $reg, (string) $this->hidden($html, 'redirect_url'));
    }

    /** Una inscripción ajena no se paga: ni como inscripción ni como donativo suelto. */
    public function test_una_inscripcion_ajena_no_se_paga()
    {
        $reg = '00000900-db01-acfe-2649-6ab30955f412';
        $this->crm->events['ev-1'] = $this->evento();
        $html = $this->formularioDePago(array('eventId' => 'ev-1', 'registrationId' => $reg));
        $this->assertNull($this->hidden($html, 'campaign_id'), 'no hay formulario');
        $this->assertStringContainsString('Este pago no está pendiente', $html);
    }

    /** Sin nada que pagar no hay formulario: desde el área no se hacen aportaciones sueltas. */
    public function test_sin_nada_que_pagar_no_hay_formulario_de_donativo()
    {
        $html = $this->formularioDePago(array('amount' => '110'));
        $this->assertNull($this->hidden($html, 'campaign_id'));
        $this->assertStringContainsString('No hay nada que pagar aquí', $html);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_la_campana_sale_de_los_ajustes_y_si_no_pagos_con_tarjeta()
    {
        $this->assertSame('00000edb-e11b-2a0f-4757-6abc23a90262', sticpa_card_campaign_id());
        update_option('sticpa_card_campaign_id', ' 11111111-2222-3333-4444-555555555555 ');
        $reg = '00000900-db01-acfe-2649-6ab30955f412';
        $this->crm->events['ev-1'] = $this->evento();
        $this->crm->myRegs[$reg] = 'confirmed';
        $html = $this->formularioDePago(array('eventId' => 'ev-1', 'registrationId' => $reg));
        $this->assertSame('11111111-2222-3333-4444-555555555555', $this->hidden($html, 'campaign_id'));
        update_option('sticpa_card_campaign_id', 'no es un id');
        $this->assertSame('00000edb-e11b-2a0f-4757-6abc23a90262', sticpa_card_campaign_id());
        unset($GLOBALS['__stic_options']['sticpa_card_campaign_id']);
    }

    public function test_la_marca_va_firmada_y_solo_vale_para_un_id_del_crm()
    {
        $a = sticpa_registration_card_marker('00000900-db01-acfe-2649-6ab30955f412');
        $this->assertMatchesRegularExpression('/^\[insc:00000900-db01-acfe-2649-6ab30955f412:[0-9a-f]{16}\]$/', $a);
        $this->assertNotSame($a, sticpa_registration_card_marker('00000900-db01-acfe-2649-6ab30955f413'));
        $this->assertSame('', sticpa_registration_card_marker("x' OR 1=1 -- aaaaaaaaaaaaaaaaaaaaaaaa"));
    }

    public function test_a_la_vuelta_se_ata_el_compromiso_cobrado_y_solo_ese()
    {
        $reg = '00000900-db01-acfe-2649-6ab30955f412';
        $marca = sticpa_registration_card_marker($reg);
        $this->crm->commitmentsByDescription = array(
            'pc-abandonado' => 'Pago con tarjeta… ' . $marca,
            'pc-cobrado' => 'Pago con tarjeta… ' . $marca,
            'pc-falso' => 'Pago con tarjeta… [insc:' . $reg . ':0000000000000000]',
        );
        $this->crm->paymentsOfCommitment = array(
            'pc-abandonado' => array('pending'),
            'pc-cobrado' => array('paid'),
            'pc-falso' => array('paid'),
        );
        $this->assertSame(1, sticpa_registration_claim_card_commitment($this->crm, $reg));
        $this->assertSame(array(array('stic_Payment_Commitments', 'pc-cobrado', 'stic_payment_commitments_stic_registrations', array($reg))), $this->crm->relations);

        // Y, aunque el CRM lo haya guardado como donativo, queda como SERVICIO:
        // el compromiso y su pago, con el nombre corregido y fuera del 182.
        $pc = $this->crm->writesTo('stic_Payment_Commitments');
        $this->assertSame(array('id' => 'pc-cobrado', 'payment_type' => 'services', 'name' => 'David Soler Balado - Servicios - 110,00'), $pc[0]['data']);
        $pay = $this->crm->writesTo('stic_Payments');
        $this->assertSame(array('id' => 'pay-0', 'payment_type' => 'services',
            'name' => 'David Soler Balado - Servicios - 110,00 - 2026-09-30', 'm182_excluded' => 1), $pay[0]['data']);
        $this->assertCount(1, $pay);
    }

    /* ---- Pagar con tarjeta lo que se debe (plan 041) ------------------- */

    const PAGO = '00000fd6-ad2f-3d62-adbc-6abd9452eef9';

    /** Un pago pendiente de 110 € del Foro, de ECE, colgado de la inscripción reg-1. */
    private function pagoPendiente(array $extra = array())
    {
        $this->crm->payments[self::PAGO] = array_merge(array(
            'id' => self::PAGO, 'name' => 'María Gascó Compte - Transferencia - 110 - 2026-10-01', 'amount' => '110.00',
            'status' => 'pending', 'payment_method' => 'transfer', 'payment_type' => 'services',
            'assigned_user_id' => 'ece', 'stic_paymebfe2itments_ida' => 'pc-orig',
        ), $extra);
        $this->crm->commitments['pc-orig'] = array('id' => 'pc-orig', 'banking_concept' => 'LC | Foro de Laicos Consolación 2026',
            'assigned_user_id' => 'ece', 'description' => 'Creado por el formulario del Foro.');
        $this->crm->regsOfCommitment['pc-orig'] = array('reg-1');
        $this->crm->myPayments = array(self::PAGO);
    }

    public function test_pagar_un_pendiente_con_tarjeta_usa_su_importe_su_tipo_y_su_dueno()
    {
        $this->pagoPendiente();
        $html = $this->formularioDePago(array('paymentId' => self::PAGO));
        $this->assertSame('110.00', $this->hidden($html, 'stic_Payment_Commitments___amount'));
        $this->assertStringContainsString('Pagar otra cantidad', $html);
        $this->assertSame('services', $this->hidden($html, 'stic_Payment_Commitments___payment_type'));
        $this->assertSame('ece', $this->hidden($html, 'assigned_user_id'), 'de quien organiza, no de la delegación de quien paga');
        $this->assertSame('LC | Foro de Laicos Consolación 2026', $this->hidden($html, 'stic_Payment_Commitments___banking_concept'));
        $this->assertStringContainsString(sticpa_pay_marker(self::PAGO), (string) $this->hidden($html, 'stic_Payment_Commitments___description'));
        $vuelta = (string) $this->hidden($html, 'redirect_url');
        $this->assertStringContainsString('single_stic_registrations&action=detail&id=reg-1', $vuelta);
        $this->assertStringContainsString('pago=' . self::PAGO, $vuelta);
    }

    public function test_no_se_paga_con_tarjeta_lo_ajeno_ni_lo_que_va_a_remesa()
    {
        $this->pagoPendiente();
        $this->crm->myPayments = array();
        $this->assertStringContainsString('Este pago no está pendiente', $this->formularioDePago(array('paymentId' => self::PAGO)));

        $this->pagoPendiente(array('status' => 'not_remitted', 'payment_method' => 'direct_debit'));
        $this->assertStringContainsString('Este pago no está pendiente', $this->formularioDePago(array('paymentId' => self::PAGO)));

        $this->pagoPendiente(array('status' => 'paid'));
        $this->assertStringContainsString('Este pago no está pendiente', $this->formularioDePago(array('paymentId' => self::PAGO)));
    }

    /**
     * LA SUSTITUCIÓN: cobrado el pago con tarjeta, hereda la inscripción y el
     * tipo del viejo, el viejo se cierra y su pago queda anulado. Y la marca se
     * reescribe, para no volver a hacerlo.
     */
    public function test_a_la_vuelta_el_pago_con_tarjeta_sustituye_al_pendiente()
    {
        $this->pagoPendiente(array('payment_type' => 'fee'));
        $marca = sticpa_pay_marker(self::PAGO);
        $this->crm->commitmentsByDescription['pc-card'] = 'Pago con tarjeta… ' . $marca;
        $this->crm->paymentsOfCommitment['pc-card'] = array('paid');
        $this->crm->paymentsOfCommitment['pc-orig'] = array(array('id' => self::PAGO, 'status' => 'pending'));

        $this->assertSame('pagado', sticpa_pay_claim($this->crm, self::PAGO));

        $this->assertContains(array('stic_Payment_Commitments', 'pc-card', 'stic_payment_commitments_stic_registrations', array('reg-1')), $this->crm->relations);
        $porId = array();
        foreach ($this->crm->writes as $w) {
            $porId[$w['id']][] = $w['data'];
        }
        // El nuevo, con el tipo del viejo (una cuota) y sin «Donativo».
        $this->assertSame('fee', $porId['pc-card'][0]['payment_type']);
        $this->assertSame('David Soler Balado - Cuota - 110,00', $porId['pc-card'][0]['name']);
        // El viejo, cerrado y con su porqué; su pago, anulado.
        $this->assertSame(date('Y-m-d'), $porId['pc-orig'][0]['end_date']);
        $this->assertStringContainsString('Pagado con tarjeta', $porId['pc-orig'][0]['description']);
        $this->assertSame(array('id' => self::PAGO, 'status' => 'cancelled'), $porId[self::PAGO][0]);
        // La marca, reescrita.
        $ultima = end($porId['pc-card']);
        $this->assertStringContainsString('[sustituido:' . self::PAGO . ']', $ultima['description']);
        $this->assertStringNotContainsString($marca, $ultima['description']);
        // Y con nombre: el concepto del viejo, no «Tarjeta (vía Redsys)».
        $this->assertContains(array('id' => 'pc-card', 'banking_concept' => 'LC | Foro de Laicos Consolación 2026'),
            array_column($this->crm->writesTo('stic_Payment_Commitments'), 'data'));
    }

    /**
     * LO QUE PASÓ EL 02/10/2026: el compromiso del FWA no tiene concepto ni la
     * API da el compromiso del pago en su campo plano. Antes, el viejo no se
     * cerraba (el pendiente seguía al lado del pagado) y todo se llamaba
     * «Tarjeta (vía Redsys)». Ahora el compromiso sale por la relación y el
     * nombre, del evento de la inscripción.
     */
    public function test_un_pendiente_del_fwa_se_sustituye_y_se_llama_como_el_evento()
    {
        $this->pagoPendiente(array('name' => 'David Soler Balado - Tarjeta (vía Redsys) - 110 - 2026-10-02', 'payment_method' => 'card'));
        $this->crm->commitments['pc-orig'] = array('id' => 'pc-orig', 'assigned_user_id' => 'ece', 'description' => '');
        // Como la inscripción del FWA: el evento, en su nombre.
        $this->crm->registrations['reg-1'] = array('name' => 'David Soler Balado - LC | Foro de Laicos Consolación 2026');
        $this->assertSame('pc-orig', sticpa_payment_commitment_id($this->crm, self::PAGO, null), 'por la relación');

        $ctx = sticpa_pay_context($this->crm, self::PAGO);
        $this->assertSame('pc-orig', $ctx['commitment_id']);
        $this->assertSame('LC | Foro de Laicos Consolación 2026', $ctx['concept'], 'del evento, no del medio de pago');

        $marca = sticpa_pay_marker(self::PAGO);
        $this->crm->commitmentsByDescription['pc-card'] = 'Pago con tarjeta… ' . $marca;
        $this->crm->paymentsOfCommitment['pc-card'] = array('paid');
        $this->crm->paymentsOfCommitment['pc-orig'] = array(array('id' => self::PAGO, 'status' => 'pending'));
        sticpa_pay_claim($this->crm, self::PAGO);
        $this->assertContains(array('id' => self::PAGO, 'status' => 'cancelled'), array_column($this->crm->writesTo('stic_Payments'), 'data'),
            'el pendiente viejo, anulado');
    }

    /** Las sustituciones que se quedaron a medias (`[pagado:…]`) se terminan al entrar en Pagos. */
    public function test_pagos_termina_una_sustitucion_a_medias()
    {
        $this->pagoPendiente();
        $this->crm->commitments['pc-card'] = array('id' => 'pc-card', 'description' => 'Pago con tarjeta… [pagado:' . self::PAGO . ']');
        $this->crm->paymentsOfCommitment['pc-card'] = array('paid');
        $this->crm->paymentsOfCommitment['pc-orig'] = array(array('id' => self::PAGO, 'status' => 'pending'));
        $fila = (object) array('id' => 'pc-card', 'name_value_list' => $this->nvl(array('id' => 'pc-card', 'name' => 'David Soler Balado - Servicios - 110,00',
            'payment_type' => 'services', 'end_date' => '', 'description' => 'Pago con tarjeta… [pagado:' . self::PAGO . ']')));

        $this->assertTrue(sticpa_payments_settle($this->crm, array($fila)));
        $datos = array_column($this->crm->writes, 'data');
        $this->assertContains(array('id' => self::PAGO, 'status' => 'cancelled'), $datos, 'el pendiente viejo se anula');
        $this->assertContains(array('stic_Payment_Commitments', 'pc-card', 'stic_payment_commitments_stic_registrations', array('reg-1')), $this->crm->relations,
            'la inscripción pasa al nuevo');
        $this->assertStringContainsString('[sustituido:' . self::PAGO . ']', end($datos)['description'], 'y no se vuelve a hacer');
    }

    /** Sin estado de «anulado» en el CRM, el pago sin cobrar del viejo se borra (no hay dinero en él). */
    public function test_sin_estado_anulado_el_pago_sin_cobrar_se_borra()
    {
        $this->crm->paymentStatusOptions = array('paid' => 'Pagado', 'pending' => 'Pendiente');
        $this->pagoPendiente();
        $this->crm->commitmentsByDescription['pc-card'] = 'Pago… ' . sticpa_pay_marker(self::PAGO);
        $this->crm->paymentsOfCommitment['pc-card'] = array('paid');
        $this->crm->paymentsOfCommitment['pc-orig'] = array(array('id' => self::PAGO, 'status' => 'pending'));
        sticpa_pay_claim($this->crm, self::PAGO);
        $this->assertContains(array('id' => self::PAGO, 'deleted' => 1), array_column($this->crm->writesTo('stic_Payments'), 'data'));
    }

    /** Un intento de tarjeta sin terminar se cierra a las 24 h, y lo que se debía sigue debiéndose. */
    public function test_un_intento_de_tarjeta_abandonado_se_cierra_y_el_pendiente_sigue()
    {
        $this->pagoPendiente();
        $marca = sticpa_pay_marker(self::PAGO);
        $this->crm->commitmentsByDescription['pc-card'] = 'Pago… ' . $marca;
        $this->crm->commitmentMeta['pc-card'] = array('date_entered' => gmdate('Y-m-d H:i:s', time() - 3 * 86400));
        $this->crm->paymentsOfCommitment['pc-card'] = array(array('id' => 'pay-intento', 'status' => 'pending'));

        $this->assertSame('abandonado', sticpa_pay_claim($this->crm, self::PAGO));
        $porId = array();
        foreach ($this->crm->writes as $w) {
            $porId[$w['id']][] = $w['data'];
        }
        $this->assertSame(date('Y-m-d'), $porId['pc-card'][0]['end_date']);
        $this->assertStringContainsString('[abandonado:', $porId['pc-card'][0]['description']);
        $this->assertSame('cancelled', $porId['pay-intento'][0]['status']);
        $this->assertArrayNotHasKey('pc-orig', $porId, 'lo que se debía no se toca');
        $this->assertSame(array(), $this->crm->relations);
    }

    /** Un intento de hace un rato (el banco aún no ha contestado) no se toca. */
    public function test_un_intento_reciente_no_se_toca()
    {
        $this->pagoPendiente();
        $this->crm->commitmentsByDescription['pc-card'] = 'Pago… ' . sticpa_pay_marker(self::PAGO);
        $this->crm->paymentsOfCommitment['pc-card'] = array('pending');
        $this->assertSame('', sticpa_pay_claim($this->crm, self::PAGO));
        $this->assertSame(array(), $this->crm->writes);
    }

    /** El estado del pago de una inscripción, en una palabra. */
    public function test_el_estado_del_pago_de_una_inscripcion()
    {
        $pc = array('id' => 'pc-1', 'amount' => '60.00', 'payment_method' => 'direct_debit', 'end_date' => '');
        $this->crm->commitmentsOfReg['reg-1'] = array($pc);

        $this->crm->paymentsOfCommitment['pc-1'] = array(array('id' => 'p1', 'status' => 'not_remitted', 'amount' => '60.00',
            'payment_date' => '2026-10-16', 'payment_method' => 'direct_debit'));
        $this->assertSame('domiciliado', sticpa_registration_payment_state($this->crm, 'reg-1', 0.0)['estado']);

        $this->crm->paymentsOfCommitment['pc-1'] = array(array('id' => 'p1', 'status' => 'pending', 'amount' => '60.00', 'payment_method' => 'bizum'));
        $estado = sticpa_registration_payment_state($this->crm, 'reg-1', 60.0);
        $this->assertSame('pendiente', $estado['estado']);
        $this->assertSame('p1', $estado['pago_id']);

        // El viejo cerrado no cuenta; el nuevo cobrado, sí.
        $this->crm->commitmentsOfReg['reg-1'] = array(array_merge($pc, array('end_date' => '2026-10-01')),
            array('id' => 'pc-2', 'amount' => '60.00', 'payment_method' => 'card', 'end_date' => ''));
        $this->crm->paymentsOfCommitment['pc-2'] = array(array('id' => 'p2', 'status' => 'paid', 'amount' => '60.00', 'payment_method' => 'card'));
        $this->assertSame('pagado', sticpa_registration_payment_state($this->crm, 'reg-1', 60.0)['estado']);

        // Sin nada anotado y con precio, como antes.
        $this->crm->commitmentsOfReg['reg-1'] = array();
        $this->assertSame('sin_compromiso', sticpa_registration_payment_state($this->crm, 'reg-1', 60.0)['estado']);
        $this->assertSame('', sticpa_registration_payment_state($this->crm, 'reg-1', 0.0)['estado']);
    }

    public function test_una_respuesta_que_no_es_opcion_no_deja_inscribirse()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->inscribirse(array('ajmcm_respuesta_1_c' => '7'));
        $this->assertStringContainsString('msg=error_respuestas', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_gratis_y_sin_preguntas_es_como_antes()
    {
        $this->crm->events['ev-1'] = $this->evento(array('price' => '0.00', 'ajmcm_pregunta_1_c' => ''));
        $this->post(array(
            'stic-action' => 'create', 'id' => '',
            'stic_registrations_stic_eventsstic_events_ida' => 'ev-1', 'status' => 'confirmed',
            // Una respuesta colada a un evento sin preguntas no se guarda.
            'ajmcm_respuesta_1_c' => 'lo que sea',
        ));
        $regs = $this->crm->writesTo('stic_Registrations');
        $this->assertCount(1, $regs);
        $this->assertArrayNotHasKey('ajmcm_respuesta_1_c', $regs[0]['data']);
        $this->assertSame(array(), $this->crm->writesTo('stic_Payment_Commitments'));
    }

    /* ---- Cancelar y modificar (EV-2) --------------------------------- */

    private function inscripcionExistente(array $evento = array(), $status = 'confirmed')
    {
        $this->crm->events['ev-1'] = $this->evento($evento);
        $this->crm->registrations['reg-1'] = array('id' => 'reg-1', 'status' => $status,
            'stic_registrations_stic_eventsstic_events_ida' => 'ev-1');
        $this->crm->myRegs = array('reg-1' => $status);
    }

    public function test_cancelar_cambia_el_estado_y_da_de_baja_su_compromiso()
    {
        $this->inscripcionExistente();
        $this->crm->commitmentsOfReg['reg-1'] = array(array('id' => 'com-1', 'description' => 'Creado desde el área', 'end_date' => ''));
        $url = $this->post(array('stic-action' => 'cancel', 'id' => 'reg-1'));

        $reg = $this->crm->writesTo('stic_Registrations');
        $this->assertSame(array('id' => 'reg-1', 'status' => 'cancelled'), $reg[0]['data'], 'se cancela, no se borra');
        $com = $this->crm->writesTo('stic_Payment_Commitments');
        $this->assertSame('com-1', $com[0]['data']['id']);
        $this->assertSame(date('Y-m-d'), $com[0]['data']['end_date']);
        $this->assertStringContainsString('cancelada desde el área privada', $com[0]['data']['description']);
        $this->assertStringContainsString('msg=cancelada', $url);
    }

    public function test_cancelar_sin_la_firma_del_formulario_no_hace_nada()
    {
        $this->inscripcionExistente();
        $this->post(array('stic-action' => 'cancel', 'id' => 'reg-1'), false);
        $this->assertSame(array(), $this->crm->writes, 'un enlace ajeno no te cancela la inscripción');
    }

    public function test_cancelar_una_inscripcion_ajena_no_hace_nada()
    {
        $this->inscripcionExistente();
        $this->crm->myRegs = array();
        $this->post(array('stic-action' => 'cancel', 'id' => 'reg-1'));
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_fuera_de_plazo_no_se_cancela_ni_se_cambia()
    {
        $this->inscripcionExistente(array('ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('-1 day'))));
        $this->assertStringContainsString('msg=cerrada', $this->post(array('stic-action' => 'cancel', 'id' => 'reg-1')));
        $this->assertStringContainsString('msg=cerrada', $this->post(array('stic-action' => 'edit', 'id' => 'reg-1', 'special_needs' => '1')));
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_si_el_crm_no_tiene_la_clave_de_cancelada_no_se_ofrece_ni_se_acepta()
    {
        $this->inscripcionExistente();
        $this->crm->statusOptions = array('confirmed' => 'Confirmada');
        $derechos = sticpa_registration_manage_rights('confirmed', $this->nvl($this->evento()), sticpa_registration_definition($this->crm));
        $this->assertTrue($derechos['editar']);
        $this->assertFalse($derechos['cancelar'], 'una clave inventada se guardaría rota, sin error');
        $this->post(array('stic-action' => 'cancel', 'id' => 'reg-1'));
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_editar_solo_cambia_lo_de_la_persona_y_nunca_el_evento()
    {
        $this->inscripcionExistente();
        $url = $this->post(array(
            'stic-action' => 'edit', 'id' => 'reg-1',
            'special_needs' => '1', 'special_needs_description' => 'Celíaca',
            'ajmcm_respuesta_1_c' => '2',
            // Lo que un formulario viejo (el genérico) sí enseñaba:
            'stic_registrations_stic_eventsstic_events_ida' => 'OTRO-EVENTO',
            'status' => 'confirmed',
        ));
        $w = $this->crm->writesTo('stic_Registrations');
        $this->assertCount(1, $w);
        $data = $w[0]['data'];
        ksort($data);
        $this->assertSame(array(
            'ajmcm_respuesta_1_c' => 'No, voy por mi cuenta',
            'id' => 'reg-1', 'special_needs' => '1', 'special_needs_description' => 'Celíaca',
        ), $data);
        $this->assertStringContainsString('msg=true', $url);
    }

    public function test_una_inscripcion_cancelada_o_de_algo_pasado_no_se_gestiona()
    {
        $def = sticpa_registration_definition($this->crm);
        $nvl = $this->nvl($this->evento());
        $this->assertFalse(sticpa_registration_manage_rights('cancelled', $nvl, $def)['editar']);
        $pasado = $this->nvl($this->evento(array('start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => date('Y-m-d', strtotime('-8 days')))));
        $this->assertFalse(sticpa_registration_manage_rights('confirmed', $pasado, $def)['editar']);
        $this->assertFalse(sticpa_registration_manage_rights('confirmed', null, $def)['editar'], 'sin saber el plazo, no');
    }

    public function test_un_curso_se_puede_modificar_pero_no_cancelar_desde_aqui()
    {
        // «MIC | Curso 2026-2027»: de septiembre a junio, y sin fechas de plazo.
        $this->inscripcionExistente(array(
            'start_date' => date('Y-m-d', strtotime('+2 days')), 'end_date' => date('Y-m-d', strtotime('+250 days')),
            'ajmcm_end_inscripcion_c' => '', 'price' => '20.00',
        ));
        $derechos = sticpa_registration_manage_rights('confirmed', $this->nvl($this->crm->events['ev-1']), sticpa_registration_definition($this->crm));
        $this->assertTrue($derechos['editar']);
        $this->assertFalse($derechos['cancelar'], 'darse de baja del curso entero se habla con la delegación');
        $this->assertStringContainsString('habla con tu delegación', $derechos['motivo']);
        $this->post(array('stic-action' => 'cancel', 'id' => 'reg-1'));
        $this->assertSame(array(), $this->crm->writes, 'y el POST tampoco lo hace');
        $html = sticpa_registration_manage_html('reg-1', $derechos);
        $this->assertStringNotContainsString("value='cancel'", $html);
        $this->assertStringContainsString('Modificar mis datos', $html);
    }

    public function test_la_ficha_ofrece_cambiar_y_cancelar_con_formulario_firmado()
    {
        $html = sticpa_registration_manage_html('reg-1', array('editar' => true, 'cancelar' => true, 'motivo' => '', 'hasta_ts' => strtotime('+10 days')));
        $this->assertStringContainsString('action=edit&amp;id=reg-1', $html);
        $this->assertStringContainsString("name='stic-action' value='cancel'", $html);
        $this->assertStringContainsString("name='stic_form_fields'", $html, 'la firma que protege de CSRF');
        $this->assertStringContainsString('sticConfirmSubmit', $html, 'con confirmación');
        $this->assertSame('', sticpa_registration_manage_html('reg-1', array('editar' => false, 'cancelar' => false)));
    }

    public function test_la_ficha_ensena_el_pago_y_la_respuesta()
    {
        $reg = sticpa_registration_view_model($this->nvl(array(
            'id' => 'reg-1', 'name' => 'INS-1', 'status' => 'confirmed',
            'stic_registrations_stic_events_name' => 'Convivencia', 'ajmcm_respuesta_1_c' => 'Sí, voy en autobús',
        )));
        $pago = array('estado' => 'pendiente', 'importe' => '60.00', 'metodo' => 'bizum', 'fecha' => '', 'pago_id' => 'pay-1');
        $html = sticpa_registration_detail_html($reg, array(), array(
            'aviso' => sticpa_registration_saved_note('true'),
            'pago' => $pago, 'metodos' => array('bizum' => 'Bizum'),
            'pagar_url' => '?internalpage=single_stic_payment_form',
        ));
        $this->assertStringContainsString('Sí, voy en autobús', $html);
        $this->assertStringContainsString('Pendiente de pagar', $html);
        $this->assertStringContainsString('Bizum', $html);
        // Se paga SU pago pendiente, y la palabra «compromiso» no sale.
        $this->assertStringContainsString('paymentId=pay-1', $html);
        $this->assertStringNotContainsString('compromiso', strtolower($html));
        $this->assertStringContainsString('stic-rec-note--ok', $html);
    }

    public function test_recien_vuelto_del_tpv_no_se_ofrece_pagar_otra_vez()
    {
        $reg = sticpa_registration_view_model($this->nvl(array('id' => 'reg-1', 'name' => 'INS-1', 'status' => 'confirmed')));
        $html = sticpa_registration_detail_html($reg, array(), array(
            'pago' => array('estado' => 'pendiente', 'importe' => '60.00', 'metodo' => 'card', 'fecha' => '', 'pago_id' => 'pay-1'),
            'esperando' => true,
        ));
        $this->assertStringNotContainsString('paymentId=', $html);
        $this->assertStringContainsString('confirmación del banco', $html);
    }

    /**
     * EL AVISO DE DESPUÉS DE APUNTARSE dice de quién es la plaza y no lleva
     * «inscrito» en masculino (plan 042, FAM-a4). Con dos hijos, «Listo: ya
     * estás inscrito» no decía a cuál se había apuntado.
     */
    public function testElAvisoDeDespuesDeApuntarseDiceDeQuienEsLaPlaza()
    {
        // Cada uno a sí mismo: segunda persona, sin género.
        $this->assertSame('Listo: ya tienes tu plaza.', sticpa_registration_saved_note('inscrita')['text']);
        $this->assertSame('Pago hecho. Ya tienes tu plaza.', sticpa_registration_saved_note('pagado')['text']);

        // Una madre viendo a su hija: con su nombre.
        $_SESSION = array(
            'scp_user_id' => 'h1',
            'scp_user_contact_name' => 'Messeguer Villarroya, Lucía',
            'scp_user_adult' => false,
            'scp_tutor_user_id' => 'f1',
            'scp_tutor_is_user' => false,
        );
        $this->assertSame('Lucía', sticpa_viendo_a_nombre());
        $this->assertSame('Listo: Lucía ya tiene su plaza.', sticpa_registration_saved_note('inscrita')['text']);
        $this->assertSame('Listo: Lucía ya tiene su plaza.', sticpa_registration_saved_note('inscrita_pago')['text']);
        $this->assertSame('Pago hecho. Lucía ya tiene su plaza.', sticpa_registration_saved_note('pagado')['text']);
        $this->assertStringStartsWith('Lucía tiene su plaza', sticpa_registration_saved_note('pago_error')['text']);
        foreach (array('inscrita', 'inscrita_pago', 'pagado', 'pago_error') as $msg) {
            $this->assertStringNotContainsString('inscrit', sticpa_registration_saved_note($msg)['text']);
        }

        // Y viéndose a sí misma, otra vez en segunda persona.
        $_SESSION['scp_tutor_is_user'] = true;
        $_SESSION['scp_user_id'] = 'f1';
        $this->assertSame('', sticpa_viendo_a_nombre());
        $this->assertSame('Listo: ya tienes tu plaza.', sticpa_registration_saved_note('inscrita')['text']);
    }
}
