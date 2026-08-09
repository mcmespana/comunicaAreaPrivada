<?php

use PHPUnit\Framework\TestCase;

/**
 * Código de acceso de 6 cifras (`inc/stic-otp.php`).
 *
 * El grueso de estos tests no va del "camino feliz" sino de los límites: seis
 * cifras solo son seguras si el contador de fallos aguanta, así que eso es lo
 * que hay que dejar clavado aquí.
 */
class OtpTest extends TestCase
{
    protected function setUp(): void
    {
        // Cada test arranca sin códigos ni contadores previos y con el reloj a 0.
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_time_offset'] = 0;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    }

    /** Adelanta el reloj de los transients. */
    private function avanzaMinutos($minutos)
    {
        $GLOBALS['__stic_time_offset'] += $minutos * 60;
    }

    /* ---------------------------------------------------------------- forma */

    public function testCodigoTieneSeisDigitos()
    {
        for ($i = 0; $i < 50; $i++) {
            $code = sticpa_otp_generate_code();
            $this->assertSame(6, strlen($code));
            $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        }
    }

    public function testCodigoConCerosPorDelanteSeConserva()
    {
        // str_pad: un 42 tiene que salir como "000042", no como "42".
        $this->assertSame('000042', str_pad('42', 6, '0', STR_PAD_LEFT));
    }

    public function testFormatoYNormalizacion()
    {
        $this->assertSame('123 456', sticpa_otp_format_code('123456'));
        // Tal y como llega si se copia del correo.
        $this->assertSame('123456', sticpa_otp_normalize_code('123 456'));
        $this->assertSame('123456', sticpa_otp_normalize_code('123-456'));
        $this->assertSame('123456', sticpa_otp_normalize_code(" 123\u{00a0}456 "));
    }

    public function testEmailEnmascarado()
    {
        $this->assertSame('ju•••••••@gmail.com', sticpa_otp_mask_email('juanperez@gmail.com'));
        $this->assertStringContainsString('@x.com', sticpa_otp_mask_email('a@x.com'));
        $this->assertSame('', sticpa_otp_mask_email('no-es-un-email'));
    }

    /* ------------------------------------------------------------ verificar */

    public function testCodigoCorrectoDevuelveModuloYContacto()
    {
        $code = sticpa_otp_issue('Ana@Example.com', 'Contacts', 'C-1');
        // El email se normaliza: da igual cómo lo teclee después.
        $this->assertSame(array('Contacts', 'C-1'), sticpa_otp_verify('ana@example.com', $code));
    }

    public function testAceptaElCodigoConElEspacioDelCorreo()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        $this->assertSame(
            array('Contacts', 'C-1'),
            sticpa_otp_verify('ana@example.com', sticpa_otp_format_code($code))
        );
    }

    public function testCodigoIncorrectoFalla()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        $otro = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->assertFalse(sticpa_otp_verify('ana@example.com', $otro));
    }

    public function testCodigoDeUnSoloUso()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        $this->assertNotFalse(sticpa_otp_verify('ana@example.com', $code));
        // El segundo intento con el mismo código ya no vale.
        $this->assertFalse(sticpa_otp_verify('ana@example.com', $code));
    }

    public function testElCodigoDeOtroEmailNoSirve()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        $this->assertFalse(sticpa_otp_verify('otra@example.com', $code));
    }

    public function testCodigoCaducaALos40Minutos()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        $this->avanzaMinutos(39);
        $this->assertNotFalse(sticpa_otp_verify('ana@example.com', $code), 'a los 39 min aún vale');

        $code2 = sticpa_otp_issue('bea@example.com', 'Contacts', 'C-2');
        $this->avanzaMinutos(41);
        $this->assertFalse(sticpa_otp_verify('bea@example.com', $code2), 'a los 41 min ya no');
    }

    /* --------------------------------------------------- límite de intentos */

    public function testBloqueoALos10Fallos()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');

        for ($i = 0; $i < sticpa_otp_max_attempts(); $i++) {
            $this->assertFalse(sticpa_otp_verify('ana@example.com', '000000'));
        }

        $this->assertTrue(sticpa_otp_is_locked('ana@example.com'));
        // Y a partir de aquí ni el código bueno entra.
        $this->assertFalse(sticpa_otp_verify('ana@example.com', $code));
    }

    /**
     * EL test importante. El contador va por email, no por código: pedir uno
     * nuevo NO devuelve intentos. Si esto se rompe, el límite de 10 no vale
     * nada, porque basta con pedir código nuevo cada 10 fallos para tener
     * intentos infinitos sobre 1 entre un millón.
     */
    public function testPedirCodigoNuevoNoReiniciaElContadorDeFallos()
    {
        sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');

        for ($i = 0; $i < 9; $i++) {
            sticpa_otp_verify('ana@example.com', '000000');
        }
        $this->assertFalse(sticpa_otp_is_locked('ana@example.com'), 'con 9 fallos aún no bloquea');

        // Pide otro código: no debería devolverle intentos.
        $nuevo = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');

        sticpa_otp_verify('ana@example.com', '000000'); // fallo nº 10
        $this->assertTrue(sticpa_otp_is_locked('ana@example.com'));
        $this->assertFalse(sticpa_otp_verify('ana@example.com', $nuevo));
    }

    public function testElBloqueoEsPorEmailYNoSalpicaAOtros()
    {
        for ($i = 0; $i < sticpa_otp_max_attempts(); $i++) {
            sticpa_otp_verify('ana@example.com', '000000');
        }
        $this->assertTrue(sticpa_otp_is_locked('ana@example.com'));

        $code = sticpa_otp_issue('bea@example.com', 'Contacts', 'C-2');
        $this->assertFalse(sticpa_otp_is_locked('bea@example.com'));
        $this->assertNotFalse(sticpa_otp_verify('bea@example.com', $code));
    }

    public function testAcertarLimpiaElContadorDeFallos()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        for ($i = 0; $i < 5; $i++) {
            sticpa_otp_verify('ana@example.com', '000000');
        }
        $this->assertNotFalse(sticpa_otp_verify('ana@example.com', $code));

        // Tras acertar, vuelve a tener los 10 intentos completos.
        $otro = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        for ($i = 0; $i < 9; $i++) {
            sticpa_otp_verify('ana@example.com', '000000');
        }
        $this->assertFalse(sticpa_otp_is_locked('ana@example.com'));
        $this->assertNotFalse(sticpa_otp_verify('ana@example.com', $otro));
    }

    public function testProbarEmailsQueNoExistenTambienGastaIntentos()
    {
        // Nunca se emitió código para esta dirección: aun así cuenta, para que
        // ir probando direcciones no salga gratis.
        for ($i = 0; $i < sticpa_otp_max_attempts(); $i++) {
            sticpa_otp_verify('nadie@example.com', '123456');
        }
        $this->assertTrue(sticpa_otp_is_locked('nadie@example.com'));
    }

    public function testUnCodigoMalFormadoNoGastaIntento()
    {
        sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse(sticpa_otp_verify('ana@example.com', '12'));
        }
        $this->assertFalse(sticpa_otp_is_locked('ana@example.com'));
    }

    public function testElBloqueoSeLevantaPasadaLaVentana()
    {
        for ($i = 0; $i < sticpa_otp_max_attempts(); $i++) {
            sticpa_otp_verify('ana@example.com', '000000');
        }
        $this->assertTrue(sticpa_otp_is_locked('ana@example.com'));

        $this->avanzaMinutos(61);
        $this->assertFalse(sticpa_otp_is_locked('ana@example.com'));
    }

    /* ---------------------------------------------------- límite de envíos */

    public function testLimiteDeEnviosPorEmail()
    {
        for ($i = 0; $i < sticpa_otp_send_max(); $i++) {
            $this->assertTrue(sticpa_otp_send_allowed('ana@example.com'));
            sticpa_otp_note_send('ana@example.com');
        }
        $this->assertFalse(sticpa_otp_send_allowed('ana@example.com'));

        // Otra dirección desde la misma IP sigue pudiendo (aún lejos del tope de IP).
        $this->assertTrue(sticpa_otp_send_allowed('bea@example.com'));
    }

    public function testLimiteDeEnviosPorIp()
    {
        // Direcciones distintas, misma IP: el tope por IP acaba cortando.
        for ($i = 0; $i < sticpa_otp_ip_send_max(); $i++) {
            sticpa_otp_note_send('persona' . $i . '@example.com');
        }
        $this->assertFalse(sticpa_otp_send_allowed('nueva@example.com'));

        // Desde otra IP, sin problema.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->assertTrue(sticpa_otp_send_allowed('nueva@example.com'));
    }

    public function testElLimiteDeEnviosSeLevantaPasadaLaVentana()
    {
        for ($i = 0; $i < sticpa_otp_send_max(); $i++) {
            sticpa_otp_note_send('ana@example.com');
        }
        $this->assertFalse(sticpa_otp_send_allowed('ana@example.com'));

        $this->avanzaMinutos(21);
        $this->assertTrue(sticpa_otp_send_allowed('ana@example.com'));
    }

    /* ------------------------------------------------------------ almacén */

    public function testElCodigoNoSeGuardaEnClaro()
    {
        $code = sticpa_otp_issue('ana@example.com', 'Contacts', 'C-1');
        $volcado = json_encode($GLOBALS['__stic_transients']);

        $this->assertStringNotContainsString($code, $volcado, 'el código no puede quedar en claro');
        $this->assertStringNotContainsString('ana@example.com', $volcado, 'el email tampoco');
    }
}
