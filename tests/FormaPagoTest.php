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

if (!function_exists('wp_kses_post')) {
    // Solo para pintar la pantalla en los tests: aquí no se sanea nada.
    function wp_kses_post($html)
    {
        return $html;
    }
}

/**
 * LA FORMA DE PAGO DE LA PERSONA (inc/stic-forma-pago.php, 05/11/2026).
 *
 * Es dinero y es el IBAN de la gente, así que lo que se prueba es lo que sería
 * grave que fallara en silencio:
 *
 *   · que NUNCA se toque un compromiso con todos sus pagos hechos, ni uno que
 *     tenga un recibo ya remesado o impagado, ni uno que no sea domiciliación;
 *   · que la cuenta nueva llegue a los pagos «No remesado» de los compromisos
 *     vivos, y solo a ellos;
 *   · que un IBAN malo no se guarde, y que dejarlo vacío mantenga el que había;
 *   · que la cuenta que cambia sea la de QUIEN PAGA según la sesión, nunca una
 *     que llegue en la petición;
 *   · que ninguna cuenta entera acabe escrita en una descripción.
 */
class FormaPagoTest extends TestCase
{
    // Un IBAN español con los dígitos de control buenos, y otro distinto.
    const IBAN_A = 'ES9300494898982516218644';
    const IBAN_B = 'ES9121000418450200051332';

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
        require_once __DIR__ . '/../inc/stic-pay-card.php';
        require_once __DIR__ . '/../inc/stic-forma-pago.php';
    }

    /** @var object */
    private $crm;

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_transients'] = array();
        $_SESSION = array(
            'scp_user_id' => 'marta',
            'scp_module' => 'Contacts',
            'scp_user_adult' => true,
            'scp_user_contact_name' => 'Pérez, Marta',
        );
        $_REQUEST = array();
        $_GET = array();
        $_SERVER['REQUEST_URI'] = '/ap/?internalpage=single_stic_forma_pago';
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

    /**
     * Un CRM que apunta lo que se escribe. `$contacts`: fichas; `$commitments`:
     * los compromisos que PAGA cada persona; `$payments`: los pagos de cada
     * compromiso. `$ignoreWrites`: campos que el CRM descarta en silencio.
     */
    private function crm()
    {
        return new class {
            public $writes = array();
            public $contacts = array();
            public $commitments = array();   // persona => [fila, …]
            public $payments = array();      // compromiso => [fila, …]
            public $commitmentsDown = false; // el CRM no contesta a los compromisos
            public $failCommitmentWrite = array();
            public $ignoreWrites = array();  // campos de Contacts que se descartan sin avisar
            public $formaOptions = array('cargo_cuenta' => 'Cargo en cuenta', 'transferencia' => 'Transferencia', 'bizum' => 'Bizum');
            private function nvl(array $f) { $o = new stdClass(); foreach ($f as $k => $v) { $o->$k = (object) array('value' => $v); } return $o; }
            public function getFieldDefinition($module, $fields)
            {
                $def = array();
                foreach ($fields as $f) {
                    $def[$f] = array('name' => $f);
                }
                if (isset($def['ajmcm_forma_pago_c'])) {
                    $o = array();
                    foreach ($this->formaOptions as $k => $v) {
                        $o[$k] = array('name' => $k, 'value' => $v);
                    }
                    $def['ajmcm_forma_pago_c']['options'] = $o;
                }
                if (isset($def['status']) && $module === 'stic_Payments') {
                    $o = array();
                    foreach (array('paid' => 'Pagado', 'pending' => 'Pendiente', 'not_remitted' => 'No remesado', 'remitted' => 'Remesado', 'unpaid' => 'Impagado', 'cancelled' => 'Anulado') as $k => $v) {
                        $o[$k] = array('name' => $k, 'value' => $v);
                    }
                    $def['status']['options'] = $o;
                }
                return (object) array('module_fields' => $def);
            }
            public function getRecordDetail($id, $module, $fields = null)
            {
                if ($module !== 'Contacts' || !isset($this->contacts[$id])) {
                    return (object) array('entry_list' => array());
                }
                return (object) array('entry_list' => array((object) array('id' => $id, 'name_value_list' => $this->nvl(array_merge(array('id' => $id), $this->contacts[$id])))));
            }
            public function getRelatedElementsForLoggedUser($p)
            {
                $link = $p['link_field_name'] ?? '';
                if ($link === 'stic_payment_commitments_contacts') {
                    if ($this->commitmentsDown) {
                        return null;
                    }
                    $out = array();
                    foreach ($this->commitments[$p['module_id']] ?? array() as $c) {
                        $out[] = (object) array('id' => $c['id'], 'name_value_list' => $this->nvl($c));
                    }
                    return $out;
                }
                if ($link === 'stic_payments_stic_payment_commitments') {
                    $out = array();
                    foreach ($this->payments[$p['module_id']] ?? array() as $row) {
                        $out[] = (object) array('id' => $row['id'], 'name_value_list' => $this->nvl($row));
                    }
                    return $out;
                }
                return array();
            }
            public function set_entry($module, $data)
            {
                if ($module === 'stic_Payment_Commitments' && in_array($data['id'] ?? '', $this->failCommitmentWrite, true)) {
                    return false;
                }
                $this->writes[] = array('module' => $module, 'data' => $data);
                if ($module === 'Contacts' && isset($this->contacts[$data['id']])) {
                    foreach ($data as $k => $v) {
                        if ($k !== 'id' && !in_array($k, $this->ignoreWrites, true)) {
                            $this->contacts[$data['id']][$k] = $v;
                        }
                    }
                }
                return $data['id'] ?? 'new';
            }
            public function writesTo($module)
            {
                return array_values(array_filter($this->writes, function ($w) use ($module) { return $w['module'] === $module; }));
            }
        };
    }

    private function com($id, $method = 'direct_debit', $account = self::IBAN_B, $end = '', $desc = '')
    {
        return array('id' => $id, 'name' => 'Compromiso ' . $id, 'description' => $desc, 'payment_method' => $method, 'bank_account' => $account, 'end_date' => $end);
    }

    private function pay($id, $status, $method = 'direct_debit')
    {
        return array('id' => $id, 'status' => $status, 'payment_method' => $method, 'name' => 'Pago ' . $id);
    }

    // ------------------------------------------------------------------
    //  Qué compromisos se pueden tocar (la regla del propietario)
    // ------------------------------------------------------------------

    public function test_un_compromiso_con_un_pago_sin_remesar_se_puede_tocar()
    {
        $r = sticpa_fp_classify(
            array('method' => 'direct_debit', 'end_date' => ''),
            array(array('id' => 'p1', 'status' => 'paid', 'method' => 'direct_debit'), array('id' => 'p2', 'status' => 'not_remitted', 'method' => 'direct_debit')),
            '2026-11-05'
        );
        $this->assertSame('tocable', $r['estado']);
        $this->assertSame(array('p2'), $r['pendientes'], 'solo el que aún no ha salido al banco');
    }

    public function test_un_compromiso_con_todos_los_pagos_hechos_no_se_toca()
    {
        $r = sticpa_fp_classify(
            array('method' => 'direct_debit', 'end_date' => ''),
            array(array('id' => 'p1', 'status' => 'paid', 'method' => 'direct_debit'), array('id' => 'p2', 'status' => 'cancelled', 'method' => 'direct_debit')),
            '2026-11-05'
        );
        $this->assertSame('sin_pendientes', $r['estado']);
        $this->assertSame(array(), $r['pendientes']);
    }

    public function test_un_recibo_ya_remesado_impagado_o_desconocido_deja_el_compromiso_como_esta()
    {
        foreach (array('remitted', 'unpaid', 'to_recover', 'lo_que_sea', '') as $estado) {
            $r = sticpa_fp_classify(
                array('method' => 'direct_debit', 'end_date' => ''),
                array(array('id' => 'p1', 'status' => 'not_remitted', 'method' => 'direct_debit'), array('id' => 'p2', 'status' => $estado, 'method' => 'direct_debit')),
                '2026-11-05'
            );
            $this->assertSame('en_curso', $r['estado'], "estado «{$estado}»");
            $this->assertSame(array(), $r['pendientes'], 'si hay un recibo en curso no se cambia NADA de ese compromiso');
        }
    }

    public function test_solo_las_domiciliaciones_vigentes()
    {
        $pays = array(array('id' => 'p1', 'status' => 'not_remitted', 'method' => 'direct_debit'));
        $this->assertSame('no_domiciliado', sticpa_fp_classify(array('method' => 'transfer_received', 'end_date' => ''), $pays, '2026-11-05')['estado']);
        $this->assertSame('no_domiciliado', sticpa_fp_classify(array('method' => 'card', 'end_date' => ''), $pays, '2026-11-05')['estado']);
        $this->assertSame('terminado', sticpa_fp_classify(array('method' => 'direct_debit', 'end_date' => '2026-11-05'), $pays, '2026-11-05')['estado'], 'la fecha de fin de hoy ya es un compromiso cerrado');
        $this->assertSame('terminado', sticpa_fp_classify(array('method' => 'direct_debit', 'end_date' => '2026-10-01'), $pays, '2026-11-05')['estado']);
        $this->assertSame('tocable', sticpa_fp_classify(array('method' => 'direct_debit', 'end_date' => '2026-12-31'), $pays, '2026-11-05')['estado']);
        $this->assertSame('tocable', sticpa_fp_classify(array('method' => 'direct_debit', 'end_date' => '0000-00-00'), $pays, '2026-11-05')['estado']);
    }

    public function test_un_no_remesado_que_no_es_domiciliacion_no_se_toca()
    {
        // El recurrente por tarjeta también nace «No remesado»: no es de este cambio.
        $r = sticpa_fp_classify(
            array('method' => 'direct_debit', 'end_date' => ''),
            array(array('id' => 'p1', 'status' => 'not_remitted', 'method' => 'card')),
            '2026-11-05'
        );
        $this->assertSame('en_curso', $r['estado']);
    }

    // ------------------------------------------------------------------
    //  Validar lo que manda el formulario
    // ------------------------------------------------------------------

    private function actual($iban = '', $titular = '', $forma = '')
    {
        return array('ok' => true, 'forma' => $forma, 'iban' => $iban, 'titular' => $titular, 'nombre' => 'Marta Pérez');
    }

    public function test_una_forma_que_no_se_ofrece_no_vale()
    {
        $m = sticpa_fp_methods($this->crm);
        foreach (array('', 'efectivo', 'direct_debit') as $forma) {
            $v = sticpa_fp_validate(array('ajmcm_forma_pago_c' => $forma), $this->actual(), $m);
            $this->assertFalse($v['ok']);
            $this->assertSame('forma', $v['error'], "forma «{$forma}»");
        }
    }

    public function test_solo_se_ofrecen_las_claves_vistas_en_datos_aunque_el_crm_tenga_mas()
    {
        $this->crm->formaOptions['efectivo_raro'] = 'Efectivo raro';
        $this->assertSame(array('cargo_cuenta', 'transferencia', 'bizum'), array_keys(sticpa_fp_methods($this->crm)));
    }

    public function test_domiciliacion_exige_un_iban_valido_y_un_titular()
    {
        $m = sticpa_fp_methods($this->crm);
        $v = sticpa_fp_validate(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => 'ES0000000000000000000000', 'ajmcm_iban_titular_c' => 'Marta'), $this->actual(), $m);
        $this->assertSame('iban', $v['error'], 'dígitos de control malos');

        $v = sticpa_fp_validate(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => '', 'ajmcm_iban_titular_c' => 'Marta'), $this->actual(), $m);
        $this->assertSame('iban', $v['error'], 'sin IBAN y sin ninguno guardado');

        $v = sticpa_fp_validate(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => self::IBAN_A, 'ajmcm_iban_titular_c' => '   '), $this->actual(), $m);
        $this->assertSame('titular', $v['error']);
    }

    public function test_un_iban_bueno_se_guarda_sin_espacios_y_pide_propagar()
    {
        $m = sticpa_fp_methods($this->crm);
        $v = sticpa_fp_validate(array(
            'ajmcm_forma_pago_c' => 'cargo_cuenta',
            'ajmcm_iban_c' => 'es93 0049 4898 9825 1621 8644',
            'ajmcm_iban_titular_c' => '  Marta   Pérez <b>x</b> ',
        ), $this->actual(), $m);
        $this->assertTrue($v['ok']);
        $this->assertSame(self::IBAN_A, $v['clean']['iban']);
        $this->assertSame('Marta Pérez x', $v['clean']['titular'], 'sin etiquetas ni espacios de más');
        $this->assertTrue($v['propagar']);
    }

    public function test_dejar_el_iban_vacio_mantiene_el_que_habia_y_no_propaga()
    {
        $m = sticpa_fp_methods($this->crm);
        $v = sticpa_fp_validate(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => '', 'ajmcm_iban_titular_c' => 'Marta Pérez'), $this->actual(self::IBAN_A, 'Marta', 'cargo_cuenta'), $m);
        $this->assertTrue($v['ok']);
        $this->assertSame(self::IBAN_A, $v['clean']['iban']);
        $this->assertFalse($v['propagar'], 'guardar solo el titular no debe pisar las cuentas de los compromisos con la de la ficha, que puede ser vieja');
    }

    public function test_con_transferencia_el_iban_que_habia_no_se_pierde()
    {
        $m = sticpa_fp_methods($this->crm);
        $v = sticpa_fp_validate(array('ajmcm_forma_pago_c' => 'transferencia', 'ajmcm_iban_c' => 'basura'), $this->actual(self::IBAN_A, 'Marta', 'cargo_cuenta'), $m);
        $this->assertTrue($v['ok']);
        $this->assertSame('transferencia', $v['clean']['forma']);
        $this->assertSame(self::IBAN_A, $v['clean']['iban']);
        $this->assertSame('Marta', $v['clean']['titular']);
        $this->assertFalse($v['propagar']);
    }

    // ------------------------------------------------------------------
    //  Llevar la cuenta a lo que no se ha cobrado
    // ------------------------------------------------------------------

    private function escenario()
    {
        $this->crm->commitments['marta'] = array(
            $this->com('A', 'direct_debit', self::IBAN_B, '', 'Nota previa'),        // 1 pago sin remesar + 1 pagado
            $this->com('B', 'direct_debit', self::IBAN_B),                           // todo pagado
            $this->com('C', 'direct_debit', self::IBAN_B),                           // un recibo ya remesado
            $this->com('D', 'transfer_received', ''),                                // no es domiciliación
            $this->com('E', 'direct_debit', self::IBAN_B, '2026-01-31'),             // terminado
            $this->com('F', 'direct_debit', self::IBAN_B),                           // dos sin remesar
        );
        $this->crm->payments = array(
            'A' => array($this->pay('A1', 'paid'), $this->pay('A2', 'not_remitted')),
            'B' => array($this->pay('B1', 'paid'), $this->pay('B2', 'paid')),
            'C' => array($this->pay('C1', 'not_remitted'), $this->pay('C2', 'remitted')),
            'D' => array($this->pay('D1', 'pending', 'transfer_received')),
            'E' => array($this->pay('E1', 'not_remitted')),
            'F' => array($this->pay('F1', 'not_remitted'), $this->pay('F2', 'not_remitted')),
        );
    }

    public function test_la_cuenta_nueva_llega_solo_a_lo_que_no_se_ha_cobrado()
    {
        $this->escenario();
        $r = sticpa_fp_propagate($this->crm, 'marta', self::IBAN_A);

        $this->assertSame(2, $r['compromisos'], 'A y F');
        $this->assertSame(3, $r['pagos'], 'A2, F1 y F2');
        $this->assertSame(1, $r['en_curso'], 'C tiene un recibo en remesa: se deja como está y se cuenta');
        $this->assertSame(0, $r['errores']);

        $comp = array_column(array_column($this->crm->writesTo('stic_Payment_Commitments'), 'data'), null, 'id');
        $this->assertSame(array('A', 'F'), array_keys($comp), 'B, C, D y E no se escriben');
        $this->assertSame(self::IBAN_A, $comp['A']['bank_account']);

        $pays = array_column($this->crm->writesTo('stic_Payments'), 'data');
        $ids = array_column($pays, 'id');
        sort($ids);
        $this->assertSame(array('A2', 'F1', 'F2'), $ids, 'ni un pago cobrado, ni un remesado, ni uno de otro medio');
        foreach ($pays as $p) {
            $this->assertSame(self::IBAN_A, $p['bank_account']);
            $this->assertSame(array('id', 'bank_account'), array_keys($p), 'del pago solo se toca la cuenta');
        }
    }

    public function test_la_descripcion_lleva_una_nota_sin_ninguna_cuenta_entera()
    {
        $this->escenario();
        sticpa_fp_propagate($this->crm, 'marta', self::IBAN_A);
        $a = array_column(array_column($this->crm->writesTo('stic_Payment_Commitments'), 'data'), null, 'id')['A'];

        $this->assertStringStartsWith('Nota previa', $a['description'], 'lo que ya había se conserva');
        $this->assertStringContainsString('[Área privada', $a['description']);
        $this->assertStringNotContainsString(self::IBAN_A, $a['description']);
        $this->assertStringNotContainsString(self::IBAN_B, $a['description']);
        $this->assertStringContainsString('ES93 •••• 8644', $a['description']);
        $this->assertStringContainsString('ES91 •••• 1332', $a['description']);
    }

    public function test_si_el_compromiso_no_se_actualiza_sus_pagos_tampoco()
    {
        $this->escenario();
        $this->crm->failCommitmentWrite = array('F');
        $r = sticpa_fp_propagate($this->crm, 'marta', self::IBAN_A);

        $this->assertSame(1, $r['compromisos']);
        $this->assertSame(1, $r['errores']);
        $ids = array_column(array_column($this->crm->writesTo('stic_Payments'), 'data'), 'id');
        $this->assertSame(array('A2'), $ids, 'o todo o nada por compromiso');
    }

    public function test_si_el_crm_no_contesta_no_se_toca_nada()
    {
        $this->escenario();
        $this->crm->commitmentsDown = true;
        $r = sticpa_fp_propagate($this->crm, 'marta', self::IBAN_A);

        $this->assertTrue($r['sin_respuesta']);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_cambiar_de_medio_no_cambia_ninguna_cuenta_pero_deja_nota()
    {
        $this->escenario();
        $marcados = sticpa_fp_flag_method_change($this->crm, 'marta', 'Transferencia');

        $this->assertSame(3, $marcados, 'A, C y F: domiciliados vivos con algo sin cobrar o en curso');
        $this->assertSame(array(), $this->crm->writesTo('stic_Payments'), 'ni un pago');
        foreach ($this->crm->writesTo('stic_Payment_Commitments') as $w) {
            $this->assertSame(array('id', 'description'), array_keys($w['data']), 'solo la nota: ni cuenta ni medio');
            $this->assertStringContainsString('ha pedido pasar a «Transferencia»', $w['data']['description']);
        }
        // Una segunda vez, con las notas ya puestas, no se vuelven a añadir.
        foreach ($this->crm->writesTo('stic_Payment_Commitments') as $w) {
            foreach ($this->crm->commitments['marta'] as &$c) {
                if ($c['id'] === $w['data']['id']) {
                    $c['description'] = $w['data']['description'];
                }
            }
            unset($c);
        }
        $this->crm->writes = array();
        $this->assertSame(0, sticpa_fp_flag_method_change($this->crm, 'marta', 'Transferencia'));
        $this->assertSame(array(), $this->crm->writes);
    }

    // ------------------------------------------------------------------
    //  El handler, de punta a punta
    // ------------------------------------------------------------------

    /** Lanza el handler con un formulario firmado y devuelve a dónde redirige. */
    private function post(array $fields, $firma = true)
    {
        $_REQUEST = $fields;
        if ($firma) {
            $_REQUEST['stic_form_fields'] = sticpa_form_token('single_stic_forma_pago', array_keys($fields));
        }
        $_REQUEST['scp_current_url'] = '/ap/?internalpage=single_stic_forma_pago';
        try {
            prefix_admin_single_stic_forma_pago();
        } catch (StiRedirect $r) {
            return $r->url;
        }
        $this->fail('el handler no redirigió');
    }

    private function fichaMarta($iban = '', $forma = '', $titular = '')
    {
        $this->crm->contacts['marta'] = array('first_name' => 'Marta', 'last_name' => 'Pérez', 'ajmcm_forma_pago_c' => $forma, 'ajmcm_iban_c' => $iban, 'ajmcm_iban_titular_c' => $titular);
    }

    public function test_guardar_un_iban_nuevo_actualiza_la_ficha_y_lo_pendiente()
    {
        $this->fichaMarta(self::IBAN_B, 'cargo_cuenta', 'Marta Pérez');
        $this->escenario();
        $url = $this->post(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => self::IBAN_A, 'ajmcm_iban_titular_c' => 'Marta Pérez'));

        $this->assertStringContainsString('msg=true', $url);
        $this->assertStringContainsString('fpp=3', $url);
        $this->assertStringContainsString('fpe=1', $url);
        $this->assertSame(self::IBAN_A, $this->crm->contacts['marta']['ajmcm_iban_c']);
        $this->assertCount(1, $this->crm->writesTo('Contacts'));
    }

    public function test_sin_la_firma_del_formulario_no_se_guarda_nada()
    {
        $this->fichaMarta(self::IBAN_B, 'cargo_cuenta', 'Marta Pérez');
        $url = $this->post(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => self::IBAN_A, 'ajmcm_iban_titular_c' => 'Marta'), false);

        $this->assertStringContainsString('msg=error', $url);
        $this->assertSame(array(), $this->crm->writes, 'es la protección contra CSRF');
    }

    public function test_un_iban_malo_no_escribe_nada()
    {
        $this->fichaMarta(self::IBAN_B, 'cargo_cuenta', 'Marta Pérez');
        $this->escenario();
        $url = $this->post(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => 'ES1234', 'ajmcm_iban_titular_c' => 'Marta'));

        $this->assertStringContainsString('msg=iban', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_cambia_la_cuenta_de_quien_paga_segun_la_sesion_no_la_que_llegue_en_la_peticion()
    {
        // Un familiar (Marta) viendo a su hija Lucía: paga Marta.
        $_SESSION['scp_user_id'] = 'lucia';
        $_SESSION['scp_tutor_user_id'] = 'marta';
        $_SESSION['scp_user_adult'] = false;
        $this->fichaMarta('', '', '');
        $this->crm->contacts['lucia'] = array('first_name' => 'Lucía', 'last_name' => 'Pérez', 'ajmcm_forma_pago_c' => '', 'ajmcm_iban_c' => '', 'ajmcm_iban_titular_c' => '');
        $this->crm->contacts['otro'] = array('first_name' => 'Otro', 'last_name' => 'Familiar', 'ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => self::IBAN_B, 'ajmcm_iban_titular_c' => 'Otro');

        // Intentan colar un id ajeno por la petición.
        $url = $this->post(array(
            'ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => self::IBAN_A, 'ajmcm_iban_titular_c' => 'Marta Pérez',
            'id' => 'otro', 'contact_id' => 'otro', 'assigned_user_id' => 'del-x',
        ));

        $this->assertStringContainsString('msg=true', $url);
        $this->assertSame(self::IBAN_A, $this->crm->contacts['marta']['ajmcm_iban_c'], 'la de quien paga');
        $this->assertSame(self::IBAN_B, $this->crm->contacts['otro']['ajmcm_iban_c'], 'la de otro familiar, intacta');
        $this->assertSame('', $this->crm->contacts['lucia']['ajmcm_iban_c'], 'el menor no tiene forma de pago propia');
        foreach ($this->crm->writesTo('Contacts') as $w) {
            $this->assertSame('marta', $w['data']['id']);
            $this->assertArrayNotHasKey('assigned_user_id', $w['data']);
        }
    }

    public function test_si_el_crm_descarta_el_guardado_no_se_dice_que_esta_bien()
    {
        // Así se perdieron los IBAN de «Mis datos (familiar)» durante meses:
        // el CRM aceptaba campos que no existían y no decía nada.
        $this->fichaMarta('', '', '');
        $this->crm->ignoreWrites = array('ajmcm_iban_c');
        $url = $this->post(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => self::IBAN_A, 'ajmcm_iban_titular_c' => 'Marta'));

        $this->assertStringContainsString('msg=error', $url);
        $this->assertSame(array(), $this->crm->writesTo('stic_Payment_Commitments'), 'y no se toca ningún compromiso');
    }

    public function test_si_un_pago_no_se_puede_actualizar_se_dice()
    {
        $this->fichaMarta('', '', '');
        $this->escenario();
        $this->crm->failCommitmentWrite = array('A');
        $url = $this->post(array('ajmcm_forma_pago_c' => 'cargo_cuenta', 'ajmcm_iban_c' => self::IBAN_A, 'ajmcm_iban_titular_c' => 'Marta'));

        $this->assertStringContainsString('msg=parcial', $url, 'la ficha se guardó pero a tesorería hay que decirle que falta algo');
    }

    public function test_pasar_a_transferencia_deja_nota_y_no_cambia_cuentas()
    {
        $this->fichaMarta(self::IBAN_A, 'cargo_cuenta', 'Marta Pérez');
        $this->escenario();
        $url = $this->post(array('ajmcm_forma_pago_c' => 'transferencia', 'ajmcm_iban_c' => '', 'ajmcm_iban_titular_c' => ''));

        $this->assertStringContainsString('msg=true', $url);
        $this->assertStringContainsString('fpm=3', $url);
        $this->assertSame(self::IBAN_A, $this->crm->contacts['marta']['ajmcm_iban_c'], 'el IBAN no se borra');
        $this->assertSame(array(), $this->crm->writesTo('stic_Payments'));
        foreach ($this->crm->writesTo('stic_Payment_Commitments') as $w) {
            $this->assertArrayNotHasKey('bank_account', $w['data']);
        }
    }

    public function test_la_persona_que_paga_sale_de_la_sesion_y_nunca_de_la_peticion()
    {
        // Guardarraíl de código: el handler no lee ningún id del request.
        $src = file_get_contents(__DIR__ . '/../inc/stic-action.php');
        $ini = strpos($src, 'function prefix_admin_single_stic_forma_pago');
        $fin = strpos($src, 'function ', $ini + 10);
        $cuerpo = substr($src, $ini, $fin - $ini);
        $this->assertStringContainsString('sticpa_require_session()', $cuerpo);
        $this->assertStringContainsString('sticpa_registration_payer()', $cuerpo);
        $this->assertStringNotContainsString("\$_REQUEST['id']", $cuerpo);
        $this->assertStringNotContainsString("\$_POST['id']", $cuerpo);
    }

    // ------------------------------------------------------------------
    //  La pantalla, pintada de verdad
    // ------------------------------------------------------------------

    /** Pinta pages/single_stic_forma_pago.php y devuelve su HTML. */
    private function pintar()
    {
        $html = '';
        $formSettings = array();
        $fieldList = array();
        $objSCP = SugarRestApiCall::$objSCP;
        include __DIR__ . '/../pages/single_stic_forma_pago.php';
        return $html;
    }

    public function test_la_pantalla_enseña_la_cuenta_tapada_y_nunca_entera()
    {
        $this->fichaMarta(self::IBAN_A, 'cargo_cuenta', 'Marta Pérez');
        $html = $this->pintar();

        $this->assertStringContainsString('ES93 •••• 8644', $html);
        $this->assertStringNotContainsString(self::IBAN_A, $html, 'ni en un value, ni en un placeholder');
        $this->assertStringNotContainsString('2516218644', $html);
        $this->assertStringContainsString('Cargo en cuenta', $html, 'la etiqueta es la que dice el CRM');
        $this->assertStringContainsString('name=\'ajmcm_forma_pago_c\'', str_replace('"', "'", $html));
        $this->assertStringContainsString('stic_form_fields', $html, 'la firma del formulario');
        $this->assertStringContainsString("data-visible-when", $html, 'el IBAN solo con domiciliación');
    }

    public function test_la_pantalla_sin_nada_guardado_lo_dice_y_ofrece_las_tres_formas()
    {
        $this->fichaMarta();
        $html = $this->pintar();

        $this->assertStringContainsString('Todavía no has indicado cómo pagas', $html);
        foreach (array('cargo_cuenta', 'transferencia', 'bizum') as $k) {
            $this->assertStringContainsString("value='{$k}'", str_replace('"', "'", $html));
        }
        $this->assertStringNotContainsString('efectivo', strtolower($html));
    }

    public function test_un_familiar_viendo_a_su_hija_edita_la_suya_y_se_lo_dice()
    {
        $_SESSION['scp_user_id'] = 'lucia';
        $_SESSION['scp_tutor_user_id'] = 'marta';
        $_SESSION['scp_user_adult'] = false;
        $_SESSION['scp_tutor_is_user'] = false;
        $_SESSION['scp_user_contact_name'] = 'Pérez, Lucía';
        $_SESSION['scp_available_profiles'] = array('lucia' => 'Lucía');
        $this->fichaMarta(self::IBAN_A, 'cargo_cuenta', 'Marta Pérez');
        $this->crm->contacts['lucia'] = array('first_name' => 'Lucía', 'last_name' => 'Pérez', 'ajmcm_forma_pago_c' => 'bizum', 'ajmcm_iban_c' => self::IBAN_B, 'ajmcm_iban_titular_c' => 'Otra');
        $html = $this->pintar();

        $this->assertStringContainsString('ES93 •••• 8644', $html, 'la cuenta de Marta, que es quien paga');
        $this->assertStringNotContainsString('1332', $html, 'la cuenta histórica de la hija no se lee');
        $this->assertStringContainsString('Lucía', $html);
        $this->assertStringContainsString('no la de', $html);
    }

    public function test_el_mensaje_de_guardado_dice_lo_que_ha_pasado_con_lo_pendiente()
    {
        $this->fichaMarta(self::IBAN_A, 'cargo_cuenta', 'Marta Pérez');
        $_REQUEST['msg'] = 'true';
        $_GET = array('fpc' => '2', 'fpp' => '3', 'fpe' => '1');
        $html = $this->pintar();

        $this->assertStringContainsString('3 pagos pendientes de cobro', $html);
        $this->assertStringContainsString('ya está en remesa', $html);
    }

    public function test_la_tarjeta_de_los_datos_del_menor_lleva_a_la_pantalla()
    {
        $this->fichaMarta(self::IBAN_A, 'cargo_cuenta', 'Marta Pérez');
        $_SESSION['scp_user_id'] = 'lucia';
        $_SESSION['scp_tutor_user_id'] = 'marta';
        $_SESSION['scp_user_adult'] = false;
        $card = sticpa_fp_card_html($this->crm);

        $this->assertStringContainsString('internalpage=single_stic_forma_pago', $card);
        $this->assertStringContainsString('ES93 •••• 8644', $card);
        $this->assertStringNotContainsString(self::IBAN_A, $card);
    }

    public function test_el_resumen_nunca_enseña_el_iban_entero()
    {
        $m = sticpa_fp_methods($this->crm);
        $txt = sticpa_fp_summary(array('forma' => 'cargo_cuenta', 'iban' => self::IBAN_A, 'titular' => 'Marta'), $m);
        $this->assertStringContainsString('ES93 •••• 8644', $txt);
        $this->assertStringNotContainsString(self::IBAN_A, $txt);
        $this->assertSame('', sticpa_fp_summary(array('forma' => '', 'iban' => self::IBAN_A, 'titular' => ''), $m));
    }
}
