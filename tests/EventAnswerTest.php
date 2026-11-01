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
 * EVENTOS «SÍ / NO» (EVENTOS.md §10.6, 01/11/2026): «Sí, vamos» / «No podemos» en vez de inscribirse.
 *
 * Lo que no puede pasar nunca:
 *   · que el «no» se confunda con «no ha contestado» (es una inscripción en `not_participate`);
 *   · dos inscripciones de la misma persona para el mismo evento: quien cambia de idea modifica la suya
 *     (la que cuenta, un «No participa» anterior o una baja);
 *   · guardar fuera de plazo, sin la firma del formulario, en un evento que no es de sí/no o con un estado
 *     que el CRM no tiene;
 *   · que un evento de pago se salte el cobro (con precio no hay modo sí/no).
 */
class EventAnswerTest extends TestCase
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
        sticpa_registration_memo_reset();
        $this->crm = $this->crm();
        SugarRestApiCall::$objSCP = $this->crm;
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
        $_REQUEST = array();
        $_SESSION = array();
        sticpa_registration_memo_reset();
    }

    private function nvl(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    /** Un evento de sí/no, sin precio y con el plazo abierto. */
    private function evento(array $extra = array())
    {
        return array_merge(array(
            'id' => 'ev-1', 'name' => 'COM | Convivencia 1.º y 2.º ESO', 'status' => 'registration',
            'start_date' => date('Y-m-d', strtotime('+20 days')), 'end_date' => date('Y-m-d', strtotime('+22 days')),
            'assigned_user_id' => 'del-cs', 'price' => '0.00',
            'ajmcm_modo_respuesta_c' => 'si_no',
            'ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('+10 days')),
        ), $extra);
    }

    private function crm()
    {
        return new class {
            public $writes = array();
            public $events = array();
            public $registrations = array();
            public $myRegs = array(); // id => estado
            public $statusOptions = array('confirmed' => 'Confirmado', 'dropped' => 'Baja', 'not_participate' => 'No participa');
            private $n = 0;
            private function nvl(array $f) { $o = new stdClass(); foreach ($f as $k => $v) { $o->$k = (object) array('value' => $v); } return $o; }
            public function getFieldDefinition($module, $fields)
            {
                $def = array();
                foreach ($fields as $f) {
                    $def[$f] = array('name' => $f);
                }
                if (isset($def['status']) && $module === 'stic_Registrations') {
                    $opts = array();
                    foreach ($this->statusOptions as $k => $v) {
                        $opts[$k] = array('name' => $k, 'value' => $v);
                    }
                    $def['status']['options'] = $opts;
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
                if ($link === 'stic_registrations_stic_events') {
                    $ev = $this->registrations[$p['module_id']]['stic_registrations_stic_eventsstic_events_ida'] ?? '';
                    return $ev !== '' ? array((object) array('id' => $ev)) : array();
                }
                return array();
            }
            public function getRecordsModule($module, $query = '', $fields = array(), $rel = null)
            {
                return array();
            }
            public function set_entry($module, $data)
            {
                $id = $data['id'] ?? ($module . '-new-' . (++$this->n));
                $this->writes[] = array('module' => $module, 'data' => $data, 'id' => $id);
                return $id;
            }
            public function writesTo($module)
            {
                return array_values(array_filter($this->writes, function ($w) use ($module) { return $w['module'] === $module; }));
            }
        };
    }

    /** Una inscripción que ya existe del evento `ev-1`. */
    private function conInscripcion($id, $status, $extra = array())
    {
        $this->crm->registrations[$id] = array_merge(array(
            'id' => $id, 'status' => $status,
            'stic_registrations_stic_eventsstic_events_ida' => 'ev-1',
        ), $extra);
        $this->crm->myRegs[$id] = $status;
    }

    /** Lanza el handler con el formulario firmado (como el del panel: la firma no lleva campos) y devuelve a dónde redirige. */
    private function responder(array $fields, $firma = true)
    {
        $_REQUEST = array_merge(array('stic-action' => 'answer', 'sticpa_event_id' => 'ev-1'), $fields);
        if ($firma) {
            $_REQUEST['stic_form_fields'] = sticpa_form_token('single_stic_registrations', array());
        }
        $_REQUEST['scp_current_url'] = '/ap/?internalpage=single_stic_events';
        try {
            prefix_admin_single_stic_registrations();
        } catch (StiRedirect $r) {
            return $r->url;
        }
        $this->fail('el handler no redirigió');
    }

    /* ---- El modo ------------------------------------------------------- */

    public function test_el_modo_sale_del_campo_del_evento()
    {
        $this->assertSame('si_no', sticpa_event_answer_mode($this->nvl($this->evento())));
        $this->assertSame('inscripcion', sticpa_event_answer_mode($this->nvl($this->evento(array('ajmcm_modo_respuesta_c' => 'inscripcion')))));
        $this->assertSame('inscripcion', sticpa_event_answer_mode($this->nvl($this->evento(array('ajmcm_modo_respuesta_c' => '')))));
        $this->assertSame('inscripcion', sticpa_event_answer_mode($this->nvl($this->evento(array('ajmcm_modo_respuesta_c' => 'raro')))));
        $this->assertSame('inscripcion', sticpa_event_answer_mode(null));
        $sin = $this->evento();
        unset($sin['ajmcm_modo_respuesta_c']);
        $this->assertSame('inscripcion', sticpa_event_answer_mode($this->nvl($sin)), 'sin el campo en el CRM, lo de siempre');
    }

    public function test_con_precio_no_hay_modo_si_no_porque_un_si_no_tiene_por_donde_pagar()
    {
        $this->assertSame('inscripcion', sticpa_event_answer_mode($this->nvl($this->evento(array('price' => '35.00')))));
    }

    public function test_cada_respuesta_es_un_estado_y_un_no_no_es_no_haber_contestado()
    {
        $this->assertSame('confirmed', sticpa_event_answer_status('yes'));
        $this->assertSame('not_participate', sticpa_event_answer_status('no'));
        $this->assertSame('', sticpa_event_answer_status('quizás'));
        $this->assertSame('yes', sticpa_event_answer_of_status('confirmed'));
        $this->assertSame('no', sticpa_event_answer_of_status('not_participate'));
        $this->assertSame('', sticpa_event_answer_of_status('dropped'), 'una baja de la inscripción de siempre no es un «no»');
        $this->assertSame('', sticpa_event_answer_of_status(''));
    }

    /* ---- Contestar: el sí y el no -------------------------------------- */

    public function test_el_si_crea_la_inscripcion_de_la_persona_de_la_sesion_con_los_comentarios()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->responder(array('sticpa_answer' => 'yes', 'sticpa_answer_note' => "Alérgico a los frutos secos\nLlegamos tarde"));

        $this->assertStringContainsString('internalpage=single_stic_events&action=detail&id=ev-1&msg=sino_si', $url);
        $w = $this->crm->writesTo('stic_Registrations');
        $this->assertCount(1, $w);
        $d = $w[0]['data'];
        $this->assertSame('confirmed', $d['status']);
        $this->assertSame('c1', $d['stic_registrations_contactscontacts_ida']);
        $this->assertSame('ev-1', $d['stic_registrations_stic_eventsstic_events_ida']);
        $this->assertSame('del-cs', $d['assigned_user_id'], 'de quien organiza el evento');
        $this->assertSame('attendant', $d['participation_type']);
        $this->assertSame('1', $d['special_needs']);
        $this->assertSame("Alérgico a los frutos secos\nLlegamos tarde", $d['special_needs_description']);
        $this->assertArrayNotHasKey('id', $d);
    }

    public function test_el_no_queda_registrado_como_no_participa_y_sin_comentarios_no_hay_necesidades()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->responder(array('sticpa_answer' => 'no', 'sticpa_answer_note' => '   '));

        $this->assertStringContainsString('msg=sino_no', $url);
        $d = $this->crm->writesTo('stic_Registrations')[0]['data'];
        $this->assertSame('not_participate', $d['status']);
        $this->assertSame('0', $d['special_needs']);
        $this->assertSame('', $d['special_needs_description']);
    }

    public function test_los_comentarios_tienen_tope()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->responder(array('sticpa_answer' => 'yes', 'sticpa_answer_note' => str_repeat('a', 900)));
        $this->assertSame(500, strlen($this->crm->writesTo('stic_Registrations')[0]['data']['special_needs_description']));
    }

    /* ---- Cambiar de idea: la MISMA inscripción -------------------------- */

    public function test_de_si_a_no_modifica_la_inscripcion_que_ya_tiene()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->conInscripcion('reg-1', 'confirmed');
        $this->responder(array('sticpa_answer' => 'no', 'sticpa_answer_note' => 'Nos ha surgido algo'));

        $w = $this->crm->writesTo('stic_Registrations');
        $this->assertCount(1, $w, 'una sola escritura, y sin crear otra inscripción');
        $this->assertSame('reg-1', $w[0]['data']['id']);
        $this->assertSame('not_participate', $w[0]['data']['status']);
        $this->assertArrayNotHasKey('stic_registrations_contactscontacts_ida', $w[0]['data']);
    }

    public function test_de_no_a_si_vuelve_a_la_misma_inscripcion_aunque_el_no_no_cuente()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->conInscripcion('reg-no', 'not_participate');
        $this->responder(array('sticpa_answer' => 'yes'));

        $w = $this->crm->writesTo('stic_Registrations');
        $this->assertCount(1, $w);
        $this->assertSame('reg-no', $w[0]['data']['id']);
        $this->assertSame('confirmed', $w[0]['data']['status']);
    }

    public function test_una_baja_anterior_tambien_se_reaprovecha()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->conInscripcion('reg-baja', 'dropped');
        $this->responder(array('sticpa_answer' => 'yes'));
        $this->assertSame('reg-baja', $this->crm->writesTo('stic_Registrations')[0]['data']['id']);
    }

    public function test_la_inscripcion_de_otro_evento_no_se_toca()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->conInscripcion('reg-otro', 'not_participate', array('stic_registrations_stic_eventsstic_events_ida' => 'ev-2'));
        $this->responder(array('sticpa_answer' => 'yes'));
        $d = $this->crm->writesTo('stic_Registrations')[0]['data'];
        $this->assertArrayNotHasKey('id', $d, 'se crea la de este evento');
        $this->assertSame('ev-1', $d['stic_registrations_stic_eventsstic_events_ida']);
    }

    public function test_la_respuesta_actual_se_lee_de_la_inscripcion_de_la_persona()
    {
        $this->conInscripcion('reg-1', 'confirmed', array('special_needs_description' => 'Celiaquía'));
        $a = sticpa_event_answer_find($this->crm, 'ev-1', true);
        $this->assertSame(array('id' => 'reg-1', 'status' => 'confirmed', 'answer' => 'yes', 'note' => 'Celiaquía'), $a);

        sticpa_registration_memo_reset();
        $this->crm->myRegs = array('reg-1' => 'not_participate');
        $this->crm->registrations['reg-1']['status'] = 'not_participate';
        $this->assertSame('no', sticpa_event_answer_find($this->crm, 'ev-1', true)['answer']);

        sticpa_registration_memo_reset();
        $this->crm->myRegs = array();
        $this->assertNull(sticpa_event_answer_find($this->crm, 'ev-1', true));
    }

    /* ---- Lo que NO se guarda ------------------------------------------- */

    public function test_sin_la_firma_del_formulario_no_se_escribe_nada()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $url = $this->responder(array('sticpa_answer' => 'yes'), false);
        $this->assertStringContainsString('list_stic_events', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_una_respuesta_rara_o_un_evento_con_id_raro_no_escriben()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->assertStringContainsString('msg=sino_error', $this->responder(array('sticpa_answer' => 'quizás')));
        $this->assertStringContainsString('list_stic_events', $this->responder(array('sticpa_answer' => 'yes', 'sticpa_event_id' => "x' OR 1=1")));
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_fuera_de_plazo_no_se_guarda_y_se_dice()
    {
        $this->crm->events['ev-1'] = $this->evento(array('ajmcm_end_inscripcion_c' => date('Y-m-d', strtotime('-2 days'))));
        $url = $this->responder(array('sticpa_answer' => 'yes'));
        $this->assertStringContainsString('msg=sino_cerrada', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_un_evento_que_no_es_de_si_no_se_contesta_inscribiendose()
    {
        $this->crm->events['ev-1'] = $this->evento(array('ajmcm_modo_respuesta_c' => 'inscripcion'));
        $url = $this->responder(array('sticpa_answer' => 'yes'));
        $this->assertStringContainsString('internalpage=single_stic_registrations&action=create&from=stic_events&id=ev-1', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_un_evento_de_pago_no_se_salta_el_cobro()
    {
        $this->crm->events['ev-1'] = $this->evento(array('price' => '60.00'));
        $url = $this->responder(array('sticpa_answer' => 'yes'));
        $this->assertStringContainsString('action=create', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_si_el_crm_no_tiene_el_estado_no_se_escribe_una_clave_rota()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->crm->statusOptions = array('confirmed' => 'Confirmado', 'dropped' => 'Baja');
        $this->assertStringContainsString('msg=sino_error', $this->responder(array('sticpa_answer' => 'no')));
        $this->assertSame(array(), $this->crm->writes);
        // El sí sigue funcionando: su clave sí existe.
        $this->assertStringContainsString('msg=sino_si', $this->responder(array('sticpa_answer' => 'yes')));
    }

    public function test_un_evento_que_no_existe_vuelve_a_eventos()
    {
        $url = $this->responder(array('sticpa_answer' => 'yes', 'sticpa_event_id' => 'no-existe'));
        $this->assertStringContainsString('list_stic_events', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    /* ---- «Mi inscripción» de un evento de sí/no ------------------------- */

    public function test_en_mi_inscripcion_de_un_si_no_no_se_cancela_ni_se_modifica_sino_que_se_cambia_la_respuesta()
    {
        $def = sticpa_registration_definition($this->crm);
        foreach (array('confirmed', 'not_participate') as $estado) {
            $r = sticpa_registration_manage_rights($estado, $this->nvl($this->evento()), $def);
            $this->assertFalse($r['editar'], $estado);
            $this->assertFalse($r['cancelar'], $estado);
            $this->assertSame('?internalpage=single_stic_events&action=detail&id=ev-1', $r['respuesta_url']);
        }
        $html = sticpa_registration_manage_html('reg-1', sticpa_registration_manage_rights('confirmed', $this->nvl($this->evento()), $def));
        $this->assertStringContainsString('Cambiar mi respuesta', $html);
        $this->assertStringNotContainsString('Cancelar mi inscripción', $html);
        $this->assertStringNotContainsString('Modificar mis datos', $html);
    }

    public function test_cancelar_o_modificar_por_post_directo_en_un_si_no_tampoco_vale()
    {
        $this->crm->events['ev-1'] = $this->evento();
        $this->conInscripcion('reg-1', 'confirmed');
        $_REQUEST = array('stic-action' => 'cancel', 'id' => 'reg-1', 'scp_current_url' => '/ap/?internalpage=single_stic_registrations');
        $_REQUEST['stic_form_fields'] = sticpa_form_token('single_stic_registrations', array());
        try {
            prefix_admin_single_stic_registrations();
            $this->fail('no redirigió');
        } catch (StiRedirect $r) {
            $this->assertStringContainsString('msg=cerrada', $r->url);
        }
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_un_evento_normal_sigue_con_sus_botones_de_siempre()
    {
        $def = sticpa_registration_definition($this->crm);
        $r = sticpa_registration_manage_rights('confirmed', $this->nvl($this->evento(array('ajmcm_modo_respuesta_c' => 'inscripcion'))), $def);
        $this->assertTrue($r['editar']);
        $this->assertTrue($r['cancelar']);
        $this->assertArrayNotHasKey('respuesta_url', $r);
    }

    /* ---- La pantalla ----------------------------------------------------- */

    public function test_el_panel_sin_contestar_pide_la_respuesta_con_los_dos_botones_y_los_comentarios()
    {
        $html = sticpa_event_answer_panel_html('ev-1', '', '', true);
        $this->assertStringContainsString('¿Vienes?', $html);
        $this->assertStringContainsString('Comentarios, necesidades, avisos…', $html);
        $this->assertStringContainsString("name='sticpa_answer' value='yes'", $html);
        $this->assertStringContainsString("name='sticpa_answer' value='no'", $html);
        $this->assertStringContainsString('Sí, vamos', $html);
        $this->assertStringContainsString('No podemos', $html);
        $this->assertStringContainsString("name='stic-action' value='answer'", $html);
        $this->assertStringNotContainsString('Puedes cambiar tu respuesta', $html);
    }

    public function test_el_panel_marca_lo_contestado_y_precarga_los_comentarios_sin_dejar_pasar_html()
    {
        $html = sticpa_event_answer_panel_html('ev-1', 'no', '<b>Hola</b> & adiós', true);
        $this->assertStringContainsString('Has dicho que no puedes venir.', $html);
        $this->assertStringContainsString("value='no' class='stic-rec-btn stic-rec-btn--ghost stic-sino-no' aria-pressed='true'", $html);
        $this->assertStringContainsString("aria-pressed='false'", $html);
        $this->assertStringContainsString('&lt;b&gt;Hola&lt;/b&gt; &amp; adiós', $html);
        $this->assertStringNotContainsString('<b>Hola</b>', $html);
    }

    public function test_ya_contestado_se_lee_lo_guardado_y_el_formulario_va_plegado_tras_un_boton_de_modificar()
    {
        $html = sticpa_event_answer_panel_html('ev-1', 'yes', 'Celiaquía', true);
        $this->assertStringContainsString('Has dicho que vienes.', $html);
        // Lo guardado se lee fuera del formulario, para que quien acaba de guardar vea que ya está.
        $leido = substr($html, 0, strpos($html, '<details'));
        $this->assertStringContainsString('Celiaquía', $leido);
        $this->assertStringContainsString("<details class='stic-sino-modificar'>", $html);
        $this->assertStringContainsString('Modificar mi respuesta', $html);
        // Y los botones están DENTRO del desplegable, no a la vista.
        $this->assertGreaterThan(strpos($html, '<details'), strpos($html, "name='sticpa_answer' value='yes'"));
        $this->assertStringContainsString('</form></details>', $html);
    }

    public function test_sin_contestar_el_formulario_esta_a_la_vista_y_sin_boton_de_modificar()
    {
        $html = sticpa_event_answer_panel_html('ev-1', '', '', true);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('Modificar mi respuesta', $html);
        $this->assertStringContainsString("name='sticpa_answer' value='yes'", $html);
    }

    public function test_con_un_hijo_se_dice_su_nombre_en_el_panel_y_en_el_aviso()
    {
        // Una madre viendo a su hija: la respuesta es de la hija.
        $_SESSION = array(
            'scp_user_id' => 'h1',
            'scp_user_contact_name' => 'Messeguer Villarroya, Lucía',
            'scp_user_adult' => false,
            'scp_tutor_user_id' => 'f1',
            'scp_tutor_is_user' => false,
        );
        $this->assertStringContainsString('¿Viene Lucía?', sticpa_event_answer_panel_html('ev-1', '', '', true));
        $this->assertStringContainsString('Lucía viene.', sticpa_event_answer_panel_html('ev-1', 'yes', '', true));
        $this->assertStringContainsString('Lucía no puede venir.', sticpa_event_answer_panel_html('ev-1', 'no', '', true));
        $this->assertSame('Apuntado: Lucía viene. Gracias por contestar.', sticpa_event_answer_saved_note('sino_si')['text']);
        $this->assertSame('Anotado: Lucía no puede venir. Gracias por avisar.', sticpa_event_answer_saved_note('sino_no')['text']);
    }

    public function test_con_un_hijo_la_inscripcion_es_de_la_hija_y_no_de_la_madre()
    {
        $_SESSION = array(
            'scp_user_id' => 'h1',
            'scp_user_contact_name' => 'Lucía',
            'scp_user_adult' => false,
            'scp_user_assigned_user_id' => 'del-cs',
            'scp_tutor_user_id' => 'f1',
            'scp_tutor_is_user' => false,
        );
        $this->crm->events['ev-1'] = $this->evento();
        $this->responder(array('sticpa_answer' => 'no'));
        $this->assertSame('h1', $this->crm->writesTo('stic_Registrations')[0]['data']['stic_registrations_contactscontacts_ida']);
    }

    public function test_cerrado_solo_se_enseña_lo_contestado_y_el_motivo_sin_formulario()
    {
        $html = sticpa_event_answer_panel_html('ev-1', 'yes', 'Celiaquía', false, 'La inscripción no está abierta.');
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringContainsString('Has dicho que vienes.', $html);
        $this->assertStringContainsString('Celiaquía', $html);
        $this->assertStringContainsString('La inscripción no está abierta.', $html);
    }

    public function test_la_ficha_con_panel_no_ofrece_inscribirse_y_sin_panel_si()
    {
        $event = sticpa_event_view_model($this->nvl($this->evento()));
        $this->assertSame('si_no', $event['modo']);

        $con = sticpa_event_detail_html($event, '', true, '', null, array('html' => sticpa_event_answer_panel_html('ev-1', '', '', true), 'note' => null));
        $this->assertStringContainsString('stic-sino', $con);
        $this->assertStringContainsString('Tu respuesta', $con);
        $this->assertStringNotContainsString('Inscribirme en esta actividad', $con);

        $sin = sticpa_event_detail_html(sticpa_event_view_model($this->nvl($this->evento(array('ajmcm_modo_respuesta_c' => 'inscripcion')))), '', true, '', null, null);
        $this->assertStringContainsString('Inscribirme en esta actividad', $sin);
        $this->assertStringNotContainsString('stic-sino', $sin);
    }

    public function test_el_aviso_de_tras_guardar_sale_en_la_ficha()
    {
        $event = sticpa_event_view_model($this->nvl($this->evento()));
        $html = sticpa_event_detail_html($event, '', true, '', null, array('html' => '<div class="stic-sino"></div>', 'note' => sticpa_event_answer_saved_note('sino_si')));
        $this->assertStringContainsString('Gracias por contestar.', $html);
        $this->assertNull(sticpa_event_answer_saved_note('cualquier-cosa'));
        $this->assertSame('warn', sticpa_event_answer_saved_note('sino_cerrada')['tone']);
    }

    public function test_en_el_listado_un_evento_de_si_no_ofrece_responder_y_no_inscribirme()
    {
        $e = sticpa_event_view_model($this->nvl($this->evento()));
        $cards = sticpa_events_cards(array($e));
        $labels = array_column($cards[0]['actions'], 'label');
        $this->assertSame(array('Responder'), $labels);

        $normal = sticpa_event_view_model($this->nvl($this->evento(array('ajmcm_modo_respuesta_c' => 'inscripcion'))));
        $this->assertSame(array('Inscribirme'), array_column(sticpa_events_cards(array($normal))[0]['actions'], 'label'));
    }
}
