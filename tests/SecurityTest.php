<?php

use PHPUnit\Framework\TestCase;

/**
 * LAS GUARDAS DE LOS HANDLERS (inc/stic-security.php, planes 001–005).
 *
 * El plugin habla con el CRM con un usuario técnico que puede con todo: lo que
 * no filtren estas funciones no lo filtra nadie. Por eso cada test de aquí es
 * un ataque concreto que antes funcionaba.
 */
class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = array('scp_module' => 'Contacts');
        $_REQUEST = array();
        $GLOBALS['__stic_filters'] = array();
    }

    /* ── 005: a dónde se vuelve ─────────────────────────────────────────── */

    public function test_la_vuelta_se_queda_en_este_sitio()
    {
        $_REQUEST['scp_current_url'] = 'https://phishing.example/area?internalpage=x';
        $this->assertSame('/area?internalpage=x', sticpa_return_url());

        // Relativa al protocolo: el host se tira y queda una ruta de aquí.
        $_REQUEST['scp_current_url'] = '//phishing.example/area';
        $this->assertSame('/area', sticpa_return_path());

        // `/\\otro-sitio`: algunos navegadores leen la barra invertida como
        // `//`. No llega nunca: o se descarta la ruta o queda una ruta local.
        $_REQUEST['scp_current_url'] = '/\\\\phishing.example';
        $this->assertStringNotContainsString('\\', sticpa_return_path());
        $this->assertStringStartsNotWith('//', sticpa_return_path());

        $_REQUEST['scp_current_url'] = 'javascript:alert(1)';
        $this->assertSame('/', sticpa_return_path());
    }

    public function test_la_vuelta_normal_no_cambia()
    {
        $_REQUEST['scp_current_url'] = '/ap/?internalpage=single_stic_documents';
        $this->assertSame('/ap/?internalpage=single_stic_documents', sticpa_return_url());
        $this->assertSame('/ap/', sticpa_return_path());

        // Sin query, igual se le puede pegar `&msg=true` detrás.
        $_REQUEST['scp_current_url'] = '/ap/';
        $this->assertSame('/ap/?', sticpa_return_url());
    }

    /* ── 002: qué campos se pueden escribir ─────────────────────────────── */

    public function test_solo_se_firman_los_campos_que_el_formulario_deja_escribir()
    {
        $campos = sticpa_form_posted_fields(array(
            array('name' => 'id', 'type' => 'hidden'),
            array('name' => 'email1'),
            array('name' => 'first_name', 'attributes' => array('disabled' => 'disabled')),
            array('name' => 'dni', 'attributes' => array('readonly' => 'readonly')),
            array('name' => 'titulo', 'type' => 'header'),
            array('name' => 'nombre', 'type' => 'readOnly'),
            array('name' => 'consentimiento_row', 'type' => 'html', 'posts' => array('ajmcm_consent_c')),
            array('type' => 'div', 'id' => 'x'),
        ));
        $this->assertSame(array('id', 'email1', 'ajmcm_consent_c'), $campos);
    }

    public function test_el_handler_solo_acepta_lo_firmado_y_nunca_lo_prohibido()
    {
        $_REQUEST = array(
            'stic_form_fields' => sticpa_form_token('single_stic_comunica_perfil', array('email1', 'aficiones', 'id', 'stic_pa_password_c')),
            'email1' => 'ana@example.test',
            'aficiones' => array('a', 'b'),
            'id' => 'OTRA-PERSONA',
            'stic_pa_password_c' => 'nueva',
            // No estaba en el formulario:
            'assigned_user_id' => 'otra-delegacion',
            'ajmcm_pa_token_c' => 'token-robado',
            'ajmcm_nivel_com_c' => 'coordinador',
        );
        $this->assertSame(
            array('email1' => 'ana@example.test', 'aficiones' => '^a^,^b^'),
            sticpa_request_to_module_data('single_stic_comunica_perfil')
        );
    }

    public function test_sin_firma_o_con_firma_manipulada_no_se_guarda_nada()
    {
        $_REQUEST = array('email1' => 'x@example.test');
        $this->assertNull(sticpa_request_to_module_data('single_stic_profile'));

        // Alguien reescribe la lista para añadirse un campo: la firma ya no cuadra.
        $token = sticpa_form_token('single_stic_profile', array('email1'));
        list($payload, $sig) = explode('.', $token);
        $falso = sticpa_b64url_encode(json_encode(array('a' => 'single_stic_profile', 'f' => array('email1', 'assigned_user_id'))));
        $_REQUEST['stic_form_fields'] = $falso . '.' . $sig;
        $this->assertNull(sticpa_request_to_module_data('single_stic_profile'));
    }

    public function test_la_firma_de_un_formulario_no_vale_en_otro()
    {
        $_REQUEST = array(
            'stic_form_fields' => sticpa_form_token('single_stic_documents', array('document_name')),
            'document_name' => 'x',
        );
        $this->assertNull(sticpa_request_to_module_data('single_stic_comunica_monitor'));
        $this->assertSame(array('document_name' => 'x'), sticpa_request_to_module_data('single_stic_documents'));
    }

    /* ── 002/003: ¿es tuyo? ─────────────────────────────────────────────── */

    private function crm(array $porEnlace)
    {
        return new class($porEnlace) {
            public $llamadas = array();
            private $porEnlace;
            public function __construct($porEnlace) { $this->porEnlace = $porEnlace; }
            public function getRelatedElementsForLoggedUser($p)
            {
                $clave = $p['module_name'] . ':' . $p['module_id'] . ':' . $p['link_field_name'];
                $this->llamadas[] = $clave;
                if (!array_key_exists($clave, $this->porEnlace)) {
                    return array();
                }
                if ($this->porEnlace[$clave] === null) {
                    return null; // el CRM no contesta
                }
                return array_map(function ($id) {
                    return (object) array('id' => $id, 'name_value_list' => (object) array('id' => (object) array('value' => $id)));
                }, $this->porEnlace[$clave]);
            }
        };
    }

    public function test_un_documento_es_tuyo_si_sale_en_tu_listado()
    {
        $_SESSION['scp_user_id'] = 'yo';
        $crm = $this->crm(array('Contacts:yo:documents' => array('doc-mio')));
        $this->assertTrue(sticpa_user_owns_record($crm, 'Documents', 'doc-mio'));
        $this->assertFalse(sticpa_user_owns_record($crm, 'Documents', 'doc-ajeno'));
        // Memorizado: la segunda pregunta no vuelve al CRM.
        $this->assertCount(1, $crm->llamadas);
    }

    public function test_sin_sesion_nada_es_tuyo()
    {
        $crm = $this->crm(array('Contacts::documents' => array('doc')));
        $this->assertFalse(sticpa_user_owns_record($crm, 'Documents', 'doc'));
        $this->assertSame(array(), $crm->llamadas);
    }

    public function test_si_el_crm_no_contesta_la_respuesta_es_no()
    {
        $_SESSION['scp_user_id'] = 'yo';
        $crm = $this->crm(array('Contacts:yo:stic_registrations_contacts' => null));
        $this->assertFalse(sticpa_user_owns_record($crm, 'stic_Registrations', 'reg-1'));
    }

    public function test_un_menor_ve_los_pagos_de_los_compromisos_que_son_para_el()
    {
        $_SESSION['scp_user_id'] = 'hija';
        $crm = $this->crm(array(
            'Contacts:hija:stic_payments_contacts' => array(),
            'stic_Payments:pago-1:stic_payments_stic_payment_commitments' => array('comp-1'),
            'Contacts:hija:stic_payment_commitments_contacts_1' => array('comp-1'),
        ));
        $this->assertTrue(sticpa_user_owns_record($crm, 'stic_Payments', 'pago-1'));
        $this->assertFalse(sticpa_user_owns_record($crm, 'stic_Payments', 'pago-de-otra-familia'));
    }

    /* ── 004: cambio de participante ────────────────────────────────────── */

    public function test_solo_se_puede_pasar_a_uno_mismo_o_a_un_participante_propio()
    {
        $_SESSION['scp_user_id'] = 'madre';
        $_SESSION['scp_user_contact_name'] = 'Vega, Ana';
        $_SESSION['scp_available_profiles'] = array(array('id' => 'hija', 'name' => 'Vega, Lucía'));

        $this->assertSame(array('id' => 'hija', 'name' => 'Vega, Lucía'), sticpa_allowed_profile('hija'));
        $this->assertSame(array('id' => 'madre', 'name' => 'Vega, Ana'), sticpa_allowed_profile('madre'));
        $this->assertNull(sticpa_allowed_profile('cualquier-otro-contacto'));
    }

    public function test_ya_cambiada_puede_volver_a_si_misma_pero_no_a_otro()
    {
        // La madre ya está viendo a la hija: el familiar está fijado aparte.
        $_SESSION['scp_tutor_user_id'] = 'madre';
        $_SESSION['scp_tutor_user_contact_name'] = 'Vega, Ana';
        $_SESSION['scp_user_id'] = 'hija';
        $_SESSION['scp_available_profiles'] = array(array('id' => 'hija', 'name' => 'Vega, Lucía'));

        $this->assertSame('madre', sticpa_allowed_profile('madre')['id']);
        $this->assertNull(sticpa_allowed_profile('otro'));
    }

    public function test_sin_sesion_no_se_cambia_a_nadie()
    {
        $this->assertNull(sticpa_allowed_profile('madre'));
    }

    /* ── 003: ficheros ──────────────────────────────────────────────────── */

    public function test_el_nombre_del_fichero_no_puede_inyectar_cabeceras()
    {
        $this->assertSame('x.pdfSet-Cookie: a=b', sticpa_header_filename("x.pdf\r\nSet-Cookie: a=b"));
        $this->assertSame('informe final.pdf', sticpa_header_filename('informe "final".pdf'));
        $this->assertSame('documento', sticpa_header_filename("\r\n"));
    }

    public function test_solo_se_suben_ficheros_de_la_lista_y_hasta_seis_megas()
    {
        $ok = array('name' => 'dni.pdf', 'size' => 1000, 'error' => UPLOAD_ERR_OK);
        $this->assertTrue(sticpa_upload_is_acceptable($ok));
        $this->assertFalse(sticpa_upload_is_acceptable(array('name' => 'shell.php') + $ok));
        $this->assertFalse(sticpa_upload_is_acceptable(array('name' => 'dni.pdf.exe') + $ok));
        $this->assertFalse(sticpa_upload_is_acceptable(array('size' => 7 * 1048576) + $ok));
        $this->assertFalse(sticpa_upload_is_acceptable(array('error' => UPLOAD_ERR_PARTIAL) + $ok));
        $this->assertFalse(sticpa_upload_is_acceptable(array('name' => 'foto.docx') + $ok, array('pdf', 'jpg')));
    }

    /* ── 008: consultas al CRM ─────────────────────────────────────────── */

    /**
     * El login por usuario y contraseña pegaba lo tecleado dentro del WHERE.
     * Con `x' OR '1'='1` se entraba como el primer contacto, sin contraseña.
     */
    public function test_una_comilla_en_el_login_no_cambia_la_consulta()
    {
        $q = "stic_pa_username_c = '" . SugarRestApiCall::quoteValue("x' OR '1'='1") . "'";
        $this->assertSame("stic_pa_username_c = 'x'' OR ''1''=''1'", $q);
        // Una barra al final no puede comerse la comilla de cierre.
        $this->assertSame('x\\\\', SugarRestApiCall::quoteValue('x\\'));
    }

    /* ── 001: ningún handler sin guarda ─────────────────────────────────── */

    /**
     * Recorre TODOS los `admin_post_nopriv_*` del plugin y exige que su
     * función compruebe la sesión. Es el test que habría cazado el agujero:
     * un handler nuevo que se registra como nopriv y se olvida de mirarla.
     *
     * Los de la lista blanca son públicos a propósito: son la puerta de
     * entrada (pedir el enlace, el código o entrar con DNI) y no pueden exigir
     * una sesión que todavía no existe.
     */
    public function test_todo_handler_nopriv_comprueba_la_sesion()
    {
        $publicos = array('sticpa_handle_send_access', 'sticpa_handle_send_access_dni', 'sticpa_handle_verify_code');
        $raiz = dirname(__DIR__);
        $archivos = array_merge(glob($raiz . '/inc/*.php'), array($raiz . '/sinergiacrm-private-area.php'));
        $revisados = 0;
        foreach ($archivos as $archivo) {
            $codigo = file_get_contents($archivo);
            preg_match_all("/add_action\\(\\s*'admin_post_nopriv_[^']+'\\s*,\\s*'([^']+)'/", $codigo, $m);
            foreach (array_unique($m[1]) as $funcion) {
                if (in_array($funcion, $publicos, true)) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/function\s+' . preg_quote($funcion, '/') . '\s*\(/', $codigo, "$funcion no está en " . basename($archivo));
                preg_match('/function\s+' . preg_quote($funcion, '/') . '\s*\([^)]*\)\s*\{(.{0,600})/s', $codigo, $cuerpo);
                $this->assertMatchesRegularExpression(
                    "/sticpa_require_session\\(\\)|empty\\(\\\$_SESSION\\['scp_user_id'\\]\\)/",
                    $cuerpo[1] ?? '',
                    "$funcion (" . basename($archivo) . ") se puede llamar sin haber entrado y no comprueba la sesión al principio"
                );
                $revisados++;
            }
        }
        $this->assertGreaterThan(10, $revisados);
    }

    /** Ningún handler vuelve a volcar el request entero en el CRM. */
    public function test_ningun_handler_vuelca_el_request_entero()
    {
        $codigo = file_get_contents(dirname(__DIR__) . '/inc/stic-action.php');
        $this->assertStringNotContainsString('foreach ($_REQUEST as', $codigo);
        $this->assertStringNotContainsString("\$_REQUEST['scp_current_url'] .", $codigo);
        $this->assertStringNotContainsString('wp_redirect(', $codigo);
    }
}
