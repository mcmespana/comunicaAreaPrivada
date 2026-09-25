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
                return (object) array('module_fields' => $def);
            }
            public function getRecordDetail($id, $module, $fields = null)
            {
                $src = $module === 'stic_Events' ? $this->events : ($module === 'stic_Registrations' ? $this->registrations : array());
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
                if ($link === 'stic_payment_commitments_stic_registrations') {
                    $out = array();
                    foreach ($this->commitmentsOfReg[$p['module_id']] ?? array() as $c) {
                        $out[] = (object) array('id' => $c['id'], 'name_value_list' => $this->nvl($c));
                    }
                    return $out;
                }
                if ($link === 'stic_registrations_stic_events') {
                    $ev = $this->registrations[$p['module_id']]['stic_registrations_stic_eventsstic_events_ida'] ?? '';
                    return $ev !== '' ? array((object) array('id' => $ev)) : array();
                }
                return array();
            }
            public function set_entry($module, $data)
            {
                $id = $data['id'] ?? ($module . '-new-' . (++$this->n));
                $this->writes[] = array('module' => $module, 'data' => $data, 'id' => $id);
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
        $this->assertSame('radio', $f[0]['type']);
        $this->assertSame('¿Cómo vienes?', $f[0]['label']);
        $this->assertSame(array('1' => 'Sí, voy en autobús', '2' => 'No, voy por mi cuenta'), $f[0]['selectValues']);
        $this->assertSame('2', $f[0]['value']);
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
        $this->assertSame('60.00', $c['amount']);
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

    public function test_con_tarjeta_se_pasa_al_formulario_de_pago_sin_crear_compromiso()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->inscribirse(array('sticpa_pago_metodo' => 'card'));
        $this->assertCount(1, $this->crm->writesTo('stic_Registrations'));
        $this->assertSame(array(), $this->crm->writesTo('stic_Payment_Commitments'),
            'el formulario de pago crea el suyo: con otro aquí serían dos cobros');
        $this->assertStringContainsString('internalpage=single_stic_payment_form', $url);
        $this->assertStringContainsString('amount=60.00', $url);
        $this->assertStringContainsString('eventId=ev-1', $url);
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
        $pago = (object) array('id' => 'com-1', 'name_value_list' => $this->nvl(array('id' => 'com-1', 'amount' => '60.00', 'payment_method' => 'bizum', 'end_date' => '')));
        $html = sticpa_registration_detail_html($reg, array(), array(
            'aviso' => sticpa_registration_saved_note('true'),
            'pagos' => array($pago), 'metodos' => array('bizum' => 'Bizum'), 'precio' => 60.0,
            'pagar_url' => '?internalpage=single_stic_payment_form',
        ));
        $this->assertStringContainsString('Sí, voy en autobús', $html);
        $this->assertStringContainsString('Bizum', $html);
        $this->assertStringContainsString("href='?internalpage=single_stic_payment_commitments&amp;action=detail&amp;id=com-1'", $html,
            'el pago lleva a su compromiso, en la misma pestaña');
        $this->assertStringNotContainsString('Pagar con tarjeta', $html, 'con compromiso no se ofrece pagar otra vez');
        $this->assertStringContainsString('stic-rec-note--ok', $html);
    }
}
