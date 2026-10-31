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
if (!function_exists('sanitize_email')) {
    function sanitize_email($e) { return filter_var(trim((string) $e), FILTER_VALIDATE_EMAIL) ?: ''; }
}
if (!function_exists('is_email')) {
    function is_email($e) { return (bool) filter_var((string) $e, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
}
if (!function_exists('wp_mail')) {
    /** Los correos «enviados» se apuntan, para mirar a quién se mandó. */
    function wp_mail($to, $subject, $body, $headers = '')
    {
        $GLOBALS['__stic_mails'][] = array('to' => $to, 'subject' => $subject);
        return true;
    }
}

/** El CRM de mentira: solo conoce los correos que se le dan. */
class CrmDeCorreos extends SugarRestApiCall
{
    public $conocidos = array();
    public $consultas = 0;
    public $caido = false;

    public function __construct()
    {
        // A propósito sin parent::__construct(): aquel abre sesión con el CRM.
    }

    public function getContactByEmail($email, $module)
    {
        $this->consultas++;
        if ($this->caido) {
            $this->lastError = 'red: timeout';
            return null;
        }
        $this->lastError = '';
        if (!isset($this->conocidos[$email])) {
            return null;
        }
        return (object) array(
            'id' => 'c-1',
            'name_value_list' => (object) array(
                'name' => (object) array('value' => 'Ana Pérez'),
                'email1' => (object) array('value' => $email),
            ),
        );
    }
}

/**
 * «ESE CORREO NO ESTÁ» (31/10/2026).
 * ----------------------------------------------------------------------------
 * Hasta hoy el acceso respondía igual para un correo registrado y para uno
 * inventado, y quien se equivocaba se quedaba esperando un mensaje que nunca
 * llegaba. Ahora se dice —y se ofrece el DNI en la misma pantalla—, y lo que
 * sustituye al silencio es un freno por IP a los correos que no existen.
 *
 * Aquí se fija lo que no puede romperse sin que nadie lo vea:
 *   · el correo que no existe vuelve al login CON el motivo, y no a «mira tu
 *     correo»;
 *   · el que existe sigue yendo a la pantalla del código, sin motivo;
 *   · una caída del CRM NO le dice a nadie que su correo no existe;
 *   · el barrido de direcciones se corta, también para las que sí existen;
 *   · lo que se pinta con el correo tecleado va escapado.
 */
class AccesoCorreoTest extends TestCase
{
    /** @var CrmDeCorreos */
    private $crm;

    public static function setUpBeforeClass(): void
    {
        // El enlace del correo pasa por la ruta puente de la app.
        require_once __DIR__ . '/../inc/stic-app-links.php';

        // La pantalla del login vive en el fichero principal del plugin, que no
        // se puede cargar entero en un test: se sacan las tres funciones que
        // pintan (el arnés `tests/manual/render-acceso.php` hace lo mismo con
        // `sticpa_icon`). Si cambian de sitio, esto falla a la vista.
        if (!function_exists('shortcode_exists')) {
            eval('function shortcode_exists($tag) { return false; }');
        }
        $fuente = file_get_contents(__DIR__ . '/../sinergiacrm-private-area.php');
        foreach (array('sticpa_icon', 'sticpa_greeting', 'sugar_crm_portal_login_form') as $funcion) {
            if (function_exists($funcion)) {
                continue;
            }
            if (!preg_match('/^function ' . $funcion . '\(.*?\n}\n/ms', $fuente, $m)) {
                throw new RuntimeException("No encuentro {$funcion}() en sinergiacrm-private-area.php");
            }
            eval($m[0]);
        }
    }

    protected function setUp(): void
    {
        $_SESSION = array();
        $_REQUEST = array();
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_mails'] = array();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->crm = new CrmDeCorreos();
        $this->crm->conocidos = array('ana@ejemplo.org' => true);
        SugarRestApiCall::$objSCP = $this->crm;
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
        $_REQUEST = array();
    }

    /** Pide acceso con ese correo y devuelve a dónde redirige. */
    private function pedirAcceso($correo)
    {
        $_REQUEST = array(
            'forgot-password-email-address' => $correo,
            'scp_current_url' => '/area-privada?stic_auth=1',
        );
        try {
            sticpa_handle_send_access();
        } catch (StiRedirect $r) {
            return $r->url;
        }
        $this->fail('El handler no redirigió');
    }

    // -----------------------------------------------------------------
    // El handler
    // -----------------------------------------------------------------

    public function test_un_correo_que_no_existe_vuelve_al_login_con_el_motivo(): void
    {
        $url = $this->pedirAcceso('nadie@ejemplo.org');

        $this->assertStringContainsString('email_error=nohay', $url);
        $this->assertStringNotContainsString('sticpa_code', $url);
        $this->assertSame('nadie@ejemplo.org', $_SESSION['sticpa_email_miss']);
        // El correo tecleado NO viaja por la URL (acaba en los logs del servidor).
        $this->assertStringNotContainsString('nadie', $url);
        // No se manda nada y no se prepara la pantalla del código.
        $this->assertSame(array(), $GLOBALS['__stic_mails']);
        $this->assertArrayNotHasKey('sticpa_otp_email', $_SESSION);
    }

    public function test_un_correo_que_existe_sigue_yendo_a_la_pantalla_del_codigo(): void
    {
        $_SESSION['sticpa_email_miss'] = 'otro@ejemplo.org';
        $url = $this->pedirAcceso('ana@ejemplo.org');

        $this->assertStringContainsString('sticpa_code=1', $url);
        $this->assertStringNotContainsString('email_error', $url);
        $this->assertSame('ana@ejemplo.org', $_SESSION['sticpa_otp_email']);
        $this->assertArrayNotHasKey('sticpa_email_miss', $_SESSION, 'el fallo de antes ya no aplica');
        $this->assertCount(1, $GLOBALS['__stic_mails']);
        $this->assertSame('ana@ejemplo.org', $GLOBALS['__stic_mails'][0]['to']);
    }

    public function test_algo_que_no_es_un_correo_se_dice_sin_preguntar_al_crm(): void
    {
        $url = $this->pedirAcceso('esto no es un correo');

        $this->assertStringContainsString('email_error=formato', $url);
        $this->assertSame(0, $this->crm->consultas);
        $this->assertSame(array(), $GLOBALS['__stic_mails']);
    }

    public function test_si_el_crm_no_contesta_no_se_dice_que_el_correo_no_existe(): void
    {
        // «No hay nadie» y «no ha contestado» devuelven lo mismo (null). Sin
        // mirar el error, una caída del CRM le diría a todo el mundo que se ha
        // equivocado de correo, y los mandaría al DNI para nada.
        $this->crm->caido = true;
        $url = $this->pedirAcceso('ana@ejemplo.org');

        $this->assertStringContainsString('email_error=crm', $url);
        $this->assertStringNotContainsString('nohay', $url);
        $this->assertArrayNotHasKey('sticpa_email_miss', $_SESSION);
        // Y no gasta el cupo de correos que no existen: no era uno.
        $this->assertTrue(sticpa_otp_miss_allowed());
        for ($i = 0; $i < sticpa_otp_miss_max(); $i++) {
            $this->pedirAcceso('ana@ejemplo.org');
        }
        $this->assertTrue(sticpa_otp_miss_allowed());
    }

    public function test_las_direcciones_que_no_existen_se_frenan_por_ip(): void
    {
        for ($i = 0; $i < sticpa_otp_miss_max(); $i++) {
            $this->assertStringContainsString('email_error=nohay', $this->pedirAcceso("nadie{$i}@ejemplo.org"));
        }
        $consultas = $this->crm->consultas;

        // Pasado el tope ni se mira, ni siquiera un correo que SÍ existe: la
        // respuesta no puede depender de la dirección.
        foreach (array('nadie@ejemplo.org', 'ana@ejemplo.org') as $correo) {
            $url = $this->pedirAcceso($correo);
            $this->assertStringContainsString('email_error=throttled', $url, $correo);
        }
        $this->assertSame($consultas, $this->crm->consultas, 'sin consultas nuevas al CRM');
        $this->assertSame(array(), $GLOBALS['__stic_mails']);
    }

    public function test_equivocarse_un_par_de_veces_no_frena_a_nadie(): void
    {
        $this->pedirAcceso('errata@ejemplo.org');
        $this->pedirAcceso('otra@ejemplo.org');
        $this->assertStringContainsString('sticpa_code=1', $this->pedirAcceso('ana@ejemplo.org'));
    }

    public function test_el_freno_es_por_ip_no_global(): void
    {
        for ($i = 0; $i < sticpa_otp_miss_max(); $i++) {
            sticpa_otp_note_miss();
        }
        $this->assertFalse(sticpa_otp_miss_allowed());

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->assertTrue(sticpa_otp_miss_allowed());
    }

    public function test_un_acierto_no_cuenta_como_correo_que_no_existe(): void
    {
        for ($i = 0; $i < sticpa_otp_miss_max() + 3; $i++) {
            // Cada vuelta a otro correo para no toparse con el tope por correo.
            $this->crm->conocidos["ana{$i}@ejemplo.org"] = true;
            $this->assertStringContainsString('sticpa_code=1', $this->pedirAcceso("ana{$i}@ejemplo.org"));
        }
        $this->assertTrue(sticpa_otp_miss_allowed());
    }

    public function test_con_el_tope_de_envios_agotado_va_a_la_pantalla_del_codigo(): void
    {
        // Quien ya tiene un código en camino lo ve ahí, con el aviso de esperar.
        for ($i = 0; $i < sticpa_otp_send_max(); $i++) {
            sticpa_otp_note_send('ana@ejemplo.org');
        }
        $url = $this->pedirAcceso('ana@ejemplo.org');

        $this->assertStringContainsString('sticpa_code=1', $url);
        $this->assertStringContainsString('otp_error=throttled', $url);
        $this->assertSame(0, $this->crm->consultas);
    }

    // -----------------------------------------------------------------
    // Lo que se pinta
    // -----------------------------------------------------------------

    public function test_el_mensaje_ensena_lo_que_se_tecleo_para_que_se_vea_la_errata(): void
    {
        $html = sticpa_email_miss_message_html('ana@ejmplo.org');

        $this->assertStringContainsString('ana@ejmplo.org', $html);
        $this->assertStringContainsString("role='alert'", $html);
        // El campo lo señala con `aria-describedby`: el id tiene que existir.
        $this->assertStringContainsString("id='stic-email-miss'", $html);
    }

    public function test_el_correo_tecleado_va_escapado(): void
    {
        $html = sticpa_email_miss_message_html('"><script>alert(1)</script>@x.org');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_sin_correo_en_la_sesion_el_mensaje_sigue_valiendo(): void
    {
        $html = sticpa_email_miss_message_html('');

        $this->assertStringContainsString('No encontramos ese correo', $html);
        $this->assertStringNotContainsString('<strong>', $html);
    }

    public function test_la_tarjeta_del_dni_va_abierta_y_con_el_boton_en_contorno(): void
    {
        $html = sticpa_email_miss_dni_html('/area?stic_auth=1');

        $this->assertStringContainsString('sticpa_send_access_dni', $html);
        $this->assertStringContainsString('name=\'sticpa_dni\'', $html);
        // Es la salida, no una ayuda plegada: nada de <details>.
        $this->assertStringNotContainsString('<details', $html);
        // Y comparte pantalla con el botón de marca del correo.
        $this->assertStringContainsString('stic-btn-soft', $html);
        $this->assertStringContainsString('Buscarme por mi DNI', $html);
    }

    public function test_el_formulario_del_dni_de_las_ayudas_sigue_con_su_boton_de_siempre(): void
    {
        foreach (array(sticpa_login_help_html('/area'), sticpa_access_rescue_html('', '/area')) as $html) {
            $this->assertStringNotContainsString('stic-btn-soft', $html);
            $this->assertStringContainsString('Enviarme el acceso', $html);
        }
    }

    public function test_cada_aviso_dice_que_hacer_ahora(): void
    {
        $this->assertNotSame('', sticpa_email_error_message('formato'));
        $this->assertNotSame('', sticpa_email_error_message('crm'));
        $this->assertStringContainsString(sticpa_support_email(), sticpa_email_error_message('throttled'));
        // `nohay` tiene su propio bloque, y un error desconocido no inventa nada.
        $this->assertSame('', sticpa_email_error_message('nohay'));
        $this->assertSame('', sticpa_email_error_message('loquesea'));
    }

    public function test_el_login_enseña_el_dni_abierto_solo_tras_un_correo_que_no_existe(): void
    {
        $_SERVER['REQUEST_URI'] = '/area-privada';
        $_SESSION['sticpa_email_miss'] = 'nadie@ejemplo.org';
        $_REQUEST['email_error'] = 'nohay';
        $_GET = array();
        $conFallo = sugar_crm_portal_login_form();

        $this->assertStringContainsString("value='nadie@ejemplo.org'", $conFallo, 'el campo vuelve relleno');
        $this->assertStringContainsString("aria-invalid='true'", $conFallo);
        $this->assertStringContainsString('stic-auth-alt', $conFallo);
        // La ayuda plegada del login se sustituye por la tarjeta, no se duplica.
        $this->assertStringNotContainsString('¿Qué correo pongo?', $conFallo);

        $_REQUEST = array();
        $normal = sugar_crm_portal_login_form();
        $this->assertStringNotContainsString('stic-auth-alt', $normal);
        $this->assertStringNotContainsString('aria-invalid', $normal);
        $this->assertStringContainsString('¿Qué correo pongo?', $normal);
    }

    public function test_un_nohay_a_pelo_en_la_url_no_inventa_un_correo(): void
    {
        // Sin nada en la sesión (otra pestaña, cookie perdida): mensaje general.
        $_SERVER['REQUEST_URI'] = '/area-privada';
        $_REQUEST['email_error'] = 'nohay';
        $_GET = array();
        $html = sugar_crm_portal_login_form();

        $this->assertStringContainsString('No encontramos ese correo', $html);
        $this->assertSame(1, preg_match("/<input type='email'[^>]*id='stic-magic-email'[^>]*>/", $html, $campo));
        $this->assertStringNotContainsString('value=', $campo[0], 'el campo no se rellena con un correo inventado');
    }
}
