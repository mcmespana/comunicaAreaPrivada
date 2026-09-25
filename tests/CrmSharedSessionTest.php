<?php

use PHPUnit\Framework\TestCase;

/**
 * PERF-03 — LA SESIÓN TÉCNICA DEL CRM, COMPARTIDA (inc/stic-class-6.php).
 *
 * El plugin entra al CRM siempre con el mismo usuario de servicio, así que la
 * sesión que renovó una persona vale para la siguiente. Antes cada persona
 * nueva pagaba un login técnico completo en su primera pantalla.
 *
 * El cliente se construye contra un puerto donde no escucha nadie: si intenta
 * hacer login, falla al instante y el session_id se queda vacío. Así se ve sin
 * red si ha reutilizado la compartida o no.
 */
class CrmSharedSessionTest extends TestCase
{
    const URL = 'http://127.0.0.1:9/service/v4_1/rest.php';
    const USER = 'tecnico';

    protected function setUp(): void
    {
        $_SESSION = array();
        $GLOBALS['__stic_transients'] = array();
        $static = new ReflectionProperty('SugarRestApiCall', 'objSCP');
        $static->setValue(null, null);
    }

    private function nuevoCliente()
    {
        $class = new ReflectionClass('SugarRestApiCall');
        $client = $class->newInstanceWithoutConstructor();
        $ctor = $class->getConstructor();
        $ctor->invoke($client, self::URL, self::USER, 'x', 'Contacts');
        return $client;
    }

    private function sessionId($client)
    {
        $p = new ReflectionProperty('SugarRestApiCall', 'session_id');
        return $p->getValue($client);
    }

    private function clave()
    {
        return 'sticpa_crm_sid_' . md5(self::URL . '|' . self::USER);
    }

    public function test_reutiliza_la_sesion_que_renovo_otra_persona()
    {
        $hace = time() - 300; // hace 5 minutos
        set_transient($this->clave(), array('id' => 'sid-compartido', 'time' => $hace), 1200);

        $client = $this->nuevoCliente();

        $this->assertSame('sid-compartido', $this->sessionId($client));
        // Se copia a la sesión PHP con SU hora: caduca cuando le toca.
        $this->assertSame('sid-compartido', $_SESSION['api_session_id']);
        $this->assertSame($hace, $_SESSION['api_session_time']);
    }

    public function test_una_compartida_caducada_no_se_usa()
    {
        set_transient($this->clave(), array('id' => 'sid-viejo', 'time' => time() - 1300), 0);

        $client = $this->nuevoCliente();

        $this->assertNotSame('sid-viejo', $this->sessionId($client));
    }

    public function test_un_login_fallido_no_se_comparte()
    {
        $client = $this->nuevoCliente(); // login contra un puerto cerrado: falla

        $this->assertEmpty($this->sessionId($client));
        $this->assertFalse(get_transient($this->clave()), 'Un id vacío no debe repartirse a todo el mundo');
    }

    public function test_la_de_la_propia_sesion_manda_sobre_la_compartida()
    {
        $_SESSION['api_session_id'] = 'sid-mio';
        $_SESSION['api_session_time'] = time() - 60;
        set_transient($this->clave(), array('id' => 'sid-otro', 'time' => time() - 30), 1200);

        $this->assertSame('sid-mio', $this->sessionId($this->nuevoCliente()));
    }

    public function test_otra_conexion_del_crm_no_reutiliza_la_sesion()
    {
        // Otra URL (se cambió la conexión en los ajustes): otra clave.
        set_transient('sticpa_crm_sid_' . md5('https://otro-crm|' . self::USER), array('id' => 'sid-otro-crm', 'time' => time()), 1200);

        $this->assertNotSame('sid-otro-crm', $this->sessionId($this->nuevoCliente()));
    }

    public function test_al_renovar_se_guarda_para_los_demas()
    {
        $client = $this->nuevoCliente();
        $store = new ReflectionMethod('SugarRestApiCall', 'storeSessionId');
        $store->invoke($client, 'sid-nuevo');

        $shared = get_transient($this->clave());
        $this->assertSame('sid-nuevo', $shared['id']);
        $this->assertEqualsWithDelta(time(), $shared['time'], 2);
    }
}
