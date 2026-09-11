<?php

use PHPUnit\Framework\TestCase;

/**
 * ENTRAR CUANDO NO SABES CON QUÉ CORREO TE DISTE DE ALTA.
 * ----------------------------------------------------------------------------
 * El flujo de acceso NUNCA dice si un correo existe —y hace bien—, así que
 * quien escribe una dirección equivocada ve lo mismo que quien la escribe bien:
 * «mira tu correo». Y se queda esperando un correo que no va a llegar.
 *
 * La salida es el documento: se busca a la persona por su DNI y se le manda el
 * acceso al correo que ya tenemos, enseñándoselo TAPADO para que lo reconozca.
 *
 * Lo que se fija aquí es lo que no puede aflojarse nunca:
 *   · el documento se normaliza (la gente lo escribe con puntos y guiones),
 *   · esta vía tiene su propio tope de intentos, y se apunta acierte o falle,
 *   · y la dirección completa NO se pinta en la pantalla, ni siquiera oculta.
 */
class AccesoDniTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = array();
        $GLOBALS['__stic_transients'] = array();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    }

    // -----------------------------------------------------------------
    // Normalizar
    // -----------------------------------------------------------------

    public function test_el_documento_se_limpia_como_lo_escribe_la_gente(): void
    {
        foreach (array('12345678z', '12.345.678-Z', ' 12345678 Z ', '12345678-z') as $tal_cual) {
            $this->assertSame('12345678Z', sticpa_dni_normalize($tal_cual), "con: {$tal_cual}");
        }
    }

    public function test_un_nie_tambien_vale(): void
    {
        $this->assertSame('X1234567L', sticpa_dni_normalize('x-1234567-l'));
    }

    public function test_lo_que_no_puede_ser_un_documento_no_llega_al_crm(): void
    {
        // Esto no valida DNIs (en el CRM hay pasaportes y documentos de otros
        // países): solo evita gastar una llamada al CRM con basura.
        $this->assertFalse(sticpa_dni_es_plausible('12'));
        $this->assertFalse(sticpa_dni_es_plausible(''));
        $this->assertFalse(sticpa_dni_es_plausible('...-...'));
        $this->assertTrue(sticpa_dni_es_plausible('12345678Z'));
        // Un pasaporte extranjero pasa: rechazarlo dejaría fuera justo a quien
        // más lío tiene para entrar.
        $this->assertTrue(sticpa_dni_es_plausible('AB123456'));
    }

    // -----------------------------------------------------------------
    // El tope de intentos
    // -----------------------------------------------------------------

    public function test_esta_via_tiene_su_propio_tope(): void
    {
        $dni = '12345678Z';
        for ($i = 0; $i < sticpa_otp_send_max(); $i++) {
            $this->assertTrue(sticpa_dni_send_allowed($dni), "intento {$i}");
            sticpa_dni_note_send($dni);
        }
        $this->assertFalse(sticpa_dni_send_allowed($dni));
    }

    public function test_el_tope_es_por_documento_no_global(): void
    {
        // Que uno se pase de vueltas no puede dejar fuera al de al lado… salvo
        // por el tope de IP, que es otra cosa y se prueba abajo.
        for ($i = 0; $i < sticpa_otp_send_max(); $i++) {
            sticpa_dni_note_send('11111111H');
        }
        $this->assertFalse(sticpa_dni_send_allowed('11111111H'));
        $this->assertTrue(sticpa_dni_send_allowed('22222222J'));
    }

    public function test_la_misma_ip_no_puede_ir_probando_documentos(): void
    {
        // Sin esto, esta puerta sería la barata para probar DNIs a lo bruto.
        for ($i = 0; $i < sticpa_otp_ip_send_max(); $i++) {
            sticpa_dni_note_send('1234567' . $i . 'Z');
        }
        $this->assertFalse(sticpa_dni_send_allowed('99999999R'));
    }

    public function test_el_intento_se_apunta_aunque_el_documento_no_exista(): void
    {
        // Si solo contáramos los aciertos, gastar cupo sería una forma de saber
        // qué documentos están dados de alta.
        sticpa_dni_note_send('00000000T');
        $ip = sticpa_otp_client_ip();
        $this->assertSame(1, (int) get_transient(sticpa_otp_key('ipsent', $ip)));
        $this->assertSame(1, (int) get_transient(sticpa_otp_key('dnisent', '00000000T')));
    }

    // -----------------------------------------------------------------
    // Lo que se le enseña a la persona
    // -----------------------------------------------------------------

    public function test_el_correo_se_ensena_tapado_y_reconocible(): void
    {
        // Tapado, pero con lo justo para decir «ah, el del trabajo».
        $masked = sticpa_otp_mask_email('david@movimientoconsolacion.com');
        $this->assertStringStartsWith('da', $masked);
        $this->assertStringEndsWith('@movimientoconsolacion.com', $masked);
        $this->assertStringNotContainsString('david@', $masked);
    }

    public function test_el_formulario_no_lleva_ninguna_direccion(): void
    {
        $html = sticpa_dni_access_form_html('/area?stic_auth=1');
        $this->assertStringContainsString('sticpa_send_access_dni', $html);
        $this->assertStringContainsString('sticpa_dni', $html);
        // Va cerrado: es la salida de emergencia, no la puerta principal.
        $this->assertStringContainsString('<details', $html);
        $this->assertStringNotContainsString(' open', $html);
    }

    public function test_cada_error_dice_que_hacer_ahora(): void
    {
        // Ninguno es «ha habido un error»: quien llega aquí ya está atascado.
        $soporte = sticpa_support_email();

        $this->assertStringContainsString($soporte, sticpa_dni_error_message('nohay'));
        $this->assertStringContainsString($soporte, sticpa_dni_error_message('sincorreo'));
        $this->assertStringContainsString('Espera', sticpa_dni_error_message('throttled'));
        $this->assertNotSame('', sticpa_dni_error_message('formato'));
        // Un error que no conocemos no inventa un mensaje.
        $this->assertSame('', sticpa_dni_error_message('loquesea'));
    }

    public function test_el_alta_lleva_fuera_del_area_privada(): void
    {
        // Aquí no se da de alta nadie: el enlace del login llevaba a
        // `?internalpage=single_stic_signup`, una página que no existe.
        $url = sticpa_signup_url();
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringNotContainsString('internalpage', $url);
    }
}
