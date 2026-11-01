<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/stic-registrations.php';

/**
 * Qué inscripciones NO cuentan (agenda, Pasar Lista, eventos de la persona). 01/11/2026: el desplegable de estado del CRM no
 * tiene `cancelled` —tiene `dropped` («Baja»)— y los eventos de sí/no guardan el «no» como `not_participate`.
 */
final class RegistrationCountsTest extends TestCase
{
    /** @dataProvider noCuentan */
    public function testNoCuentan($status)
    {
        $this->assertFalse(sticpa_registration_counts($status), $status);
    }

    public static function noCuentan()
    {
        return array('baja' => array('dropped'), 'dijo que no' => array('not_participate'), 'la clave antigua' => array('cancelled'), 'con mayúsculas y espacios' => array(' Dropped '));
    }

    /** @dataProvider cuentan */
    public function testCuentan($status)
    {
        $this->assertTrue(sticpa_registration_counts($status), $status);
    }

    public static function cuentan()
    {
        // Ante la duda, que salga: vacío, confirmada, en espera, quizás, rechazada (como hasta ahora) y lo que nadie ha visto.
        return array(array('confirmed'), array(''), array('waiting_list'), array('maybe'), array('rejected'), array('participates'), array('algo_nuevo'));
    }

    public function testElCancelarDelAreaEscribeLaClaveQueExisteEnElCrm()
    {
        $this->assertSame('dropped', sticpa_registration_cancel_status());
    }
}
