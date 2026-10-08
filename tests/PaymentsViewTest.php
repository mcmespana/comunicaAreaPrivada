<?php

use PHPUnit\Framework\TestCase;

/**
 * PAGOS Y COMPROMISOS DE PAGO (inc/stic-payments.php).
 *
 * Aquí se toca dinero, así que lo que se prueba es lo que sería grave que se
 * rompiera en silencio: que un recibo devuelto no se lea como cobrado, que
 * nunca se pinte un IBAN entero, y que no se ofrezca pagar un compromiso que
 * ya terminó.
 */
class PaymentsViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-payments.php';
    }

    private function nvl(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    private function row(array $fields)
    {
        $r = new stdClass();
        $r->name_value_list = $this->nvl($fields);
        return $r;
    }

    private function defPagos()
    {
        return array(
            'status' => array('options' => array(
                'settled'  => array('value' => 'Cobrado'),
                'returned' => array('value' => 'Devuelto'),
            )),
            'payment_method' => array('options' => array(
                'direct_debit' => array('value' => 'Domiciliación bancaria'),
            )),
            'sepa_rejected_reason' => array('options' => array(
                'AC04' => array('value' => 'la cuenta está cancelada'),
            )),
        );
    }

    private function defCom()
    {
        return array('periodicity' => array('options' => array(
            'monthly' => array('value' => 'Mensual'),
        )));
    }

    /* ---------------------------------------------------------------- Pagos */

    /**
     * El listado NO pedía `payment_date`: era una lista de recibos que no decía
     * cuándo te habían cobrado. Que no vuelva a caerse.
     */
    public function testElListadoPideLaFechaDelPago()
    {
        $this->assertContains('payment_date', sticpa_payment_list_fields());
        $this->assertContains('amount', sticpa_payment_list_fields());
        // Y NO pide la cuenta: en una tarjeta no cabe un IBAN, y entero no se
        // enseña nunca. Sigue en la ficha, enmascarado.
        $this->assertNotContains('bank_account', sticpa_payment_list_fields());
        $this->assertContains('bank_account', sticpa_payment_detail_fields());
    }

    /** Nunca se pinta un IBAN entero: esta pantalla se abre en el metro. */
    public function testLaCuentaSiempreSaleEnmascarada()
    {
        $mask = sticpa_payment_mask_account('ES12 0049 1234 5678 9012 3456');
        $this->assertStringNotContainsString('0049', $mask);
        $this->assertStringNotContainsString('9012', $mask);
        $this->assertStringStartsWith('ES12', $mask);
        $this->assertStringEndsWith('3456', $mask);
        $this->assertSame('', sticpa_payment_mask_account(''));
        // Una cadena corta no se parte en algo sin sentido.
        $this->assertSame('1234', sticpa_payment_mask_account('1234'));
    }

    /** Un recibo devuelto no se lee igual que uno cobrado. */
    public function testUnReciboDevueltoSeVeDevuelto()
    {
        $html = sticpa_payments_list_html(array(
            $this->row(array('id' => 'p1', 'name' => 'Cuota', 'amount' => '120.00',
                'payment_date' => '2026-03-14', 'status' => 'returned',
                'payment_method' => 'direct_debit')),
        ), $this->defPagos());

        $this->assertStringContainsString('stic-rec-chip--danger', $html);
        $this->assertStringContainsString('Devuelto', $html);
        // Es algo que se debe (plan 041): va en «Pendiente de pagar», y se
        // puede pagar con tarjeta desde ahí.
        $this->assertStringContainsString('Pendiente de pagar', $html);
        $this->assertStringContainsString('paymentId=p1', $html);
    }

    /**
     * LOS TRES BLOQUES (plan 041): lo que debes, lo que se cobrará y lo
     * pagado, y en ese orden. Ni los intentos de tarjeta ni lo pendiente de un
     * compromiso cerrado son una deuda.
     */
    public function testLosPagosVanEnTresBloques()
    {
        $html = sticpa_payments_list_html(array(
            $this->row(array('id' => 'pag', 'name' => 'Ana - COM | Curso 2026-2027 · CS - Domiciliación - 20 - 2026-10-01', 'amount' => '20.00',
                'payment_date' => '2026-10-01', 'status' => 'paid', 'payment_method' => 'direct_debit')),
            $this->row(array('id' => 'dom', 'name' => 'Sandra Roy - Maria Lorenz Roy - COM | Convivencia Inicial 2026 · Buñol · CS - Domiciliación - 60 - 2026-10-16',
                'amount' => '60.00', 'payment_date' => '2026-10-16', 'status' => 'not_remitted', 'payment_method' => 'direct_debit')),
            $this->row(array('id' => 'pen', 'name' => 'David Soler - LC | Foro de Laicos 2026', 'amount' => '110.00',
                'payment_date' => '2026-10-01', 'status' => 'pending', 'payment_method' => 'transfer', 'stic_paymebfe2itments_ida' => 'pc-foro')),
            $this->row(array('id' => 'intento', 'name' => 'David Soler - Donativo - 110,00 - 2026-10-01', 'amount' => '110.00',
                'payment_date' => '2026-10-01', 'status' => 'pending', 'payment_method' => 'card', 'stic_paymebfe2itments_ida' => 'pc-tpv')),
            $this->row(array('id' => 'viejo', 'name' => 'David Soler - LC | Foro de Laicos 2026', 'amount' => '110.00',
                'payment_date' => '2026-09-30', 'status' => 'pending', 'payment_method' => 'kind', 'stic_paymebfe2itments_ida' => 'pc-cerrado')),
        ), $this->defPagos(), array(
            'pc-foro'    => array('end_date' => '', 'payment_method' => 'transfer', 'channel' => '', 'description' => '', 'banking_concept' => ''),
            'pc-tpv'     => array('end_date' => '', 'payment_method' => 'card', 'channel' => 'web', 'description' => 'Donativo web', 'banking_concept' => ''),
            'pc-cerrado' => array('end_date' => '2026-10-01', 'payment_method' => 'kind', 'channel' => '', 'description' => '', 'banking_concept' => ''),
        ));
        $pendiente = strpos($html, 'Pendiente de pagar');
        $domiciliado = strpos($html, 'Se cobrará por domiciliación');
        $pagado = strpos($html, '>Pagado<');
        $this->assertNotFalse($pendiente);
        $this->assertLessThan($domiciliado, $pendiente);
        $this->assertLessThan($pagado, $domiciliado);
        // El título es DE QUÉ es, no la ristra del CRM; y para quién, si es de un hijo.
        $this->assertStringContainsString('>COM | Convivencia Inicial 2026 · Buñol · CS<', $html);
        $this->assertStringContainsString('Para Maria Lorenz Roy', $html);
        $this->assertStringNotContainsString('Domiciliación - 60', $html);
        // Lo que se debe, con su botón; el intento y lo cerrado, fuera.
        $this->assertStringContainsString('paymentId=pen', $html);
        $this->assertStringNotContainsString('id=intento', $html);
        $this->assertStringNotContainsString('id=viejo', $html);
        $this->assertStringNotContainsString('paymentId=dom', $html, 'lo que va a remesa no se paga con tarjeta');
    }

    /** En la ficha, lo primero que se lee es POR QUÉ falló y qué hacer. */
    public function testLaFichaDeUnDevueltoDiceElMotivoYQueHacer()
    {
        $pay = sticpa_payment_view_model($this->nvl(array(
            'id' => 'p1', 'name' => 'Cuota', 'amount' => '120.00',
            'payment_date' => '2026-03-14', 'status' => 'returned',
            'payment_method' => 'direct_debit',
            'rejection_date' => '2026-03-20', 'sepa_rejected_reason' => 'AC04',
            'bank_account' => 'ES1200491234567890123456',
        )));
        $html = sticpa_payment_detail_html($pay, $this->defPagos());

        $this->assertStringContainsString('stic-rec-note--danger', $html);
        $this->assertStringContainsString('la cuenta está cancelada', $html);
        $this->assertStringContainsString('delegación', $html);
        // El motivo se lee ANTES que los datos clave.
        $this->assertLessThan(strpos($html, 'stic-rec-facts'), strpos($html, 'stic-rec-note--danger'));
    }

    /** De los tres campos de motivo, se coge el que venga relleno. */
    public function testElMotivoDeLaDevolucion()
    {
        $def = $this->defPagos();
        $this->assertSame('la cuenta está cancelada',
            sticpa_payment_rejection_reason($this->nvl(array('sepa_rejected_reason' => 'AC04')), $def));
        // El de la pasarela es texto libre y va el último.
        $this->assertSame('Tarjeta caducada',
            sticpa_payment_rejection_reason($this->nvl(array('gateway_rejection_reason' => 'Tarjeta caducada')), $def));
        $this->assertSame('', sticpa_payment_rejection_reason($this->nvl(array()), $def));
    }

    /** Lo más reciente arriba: se entra a ver el último recibo. */
    public function testLosPagosVanDelMasRecienteAlMasAntiguo()
    {
        $html = sticpa_payments_list_html(array(
            $this->row(array('id' => 'a', 'name' => 'El viejo', 'amount' => '1.00', 'payment_date' => '2020-01-01', 'status' => 'paid')),
            $this->row(array('id' => 'b', 'name' => 'El nuevo', 'amount' => '2.00', 'payment_date' => '2026-08-01', 'status' => 'paid')),
        ), $this->defPagos());
        $this->assertLessThan(strpos($html, 'El viejo'), strpos($html, 'El nuevo'));
    }

    /** La fecha del pago no se dice dos veces en la misma ficha. */
    public function testLaFechaNoSeRepiteEnLaFichaDeUnPago()
    {
        $pay = sticpa_payment_view_model($this->nvl(array(
            'id' => 'p1', 'name' => 'Cuota', 'amount' => '20.00',
            'payment_date' => '2026-08-01', 'status' => 'settled',
        )));
        $html = sticpa_payment_detail_html($pay, $this->defPagos());
        $this->assertStringNotContainsString('Fecha del pago', $html);
    }

    /** «Servicios» no se enseña, y el código del TPV es un «Nº de operación» (08/10/2026). */
    public function testLaFichaDeUnPagoConTarjetaNoEnseñaServiciosNiReferencia()
    {
        $pay = sticpa_payment_view_model($this->nvl(array(
            'id' => 'p1', 'name' => 'Foro', 'amount' => '110.00', 'payment_date' => '2026-10-02',
            'status' => 'paid', 'payment_method' => 'card', 'payment_type' => 'services',
            'transaction_code' => '320',
        )));
        $html = sticpa_payment_detail_html($pay, $this->defPagos());
        $this->assertStringNotContainsString('>Tipo<', $html);
        $this->assertStringNotContainsString('>Referencia<', $html);
        $this->assertStringContainsString('Nº de operación', $html);
        $this->assertStringContainsString('320', $html);
    }

    /* -------------------------------------------------------- Compromisos */

    /** Un compromiso con fecha de fin pasada está terminado, diga lo que diga
     *  la casilla `active`: la fecha es un hecho, la casilla una intención. */
    public function testLaFechaDeFinManda()
    {
        $com = sticpa_commitment_view_model($this->nvl(array(
            'id' => 'c1', 'name' => 'X', 'amount' => '10.00',
            'active' => '1', 'end_date' => '2020-01-01',
        )));
        $this->assertTrue($com['terminado']);
        $this->assertFalse($com['active']);
    }

    /** A un compromiso terminado no se le ofrece pagar. */
    public function testUnCompromisoTerminadoNoOfrecePagar()
    {
        $com = sticpa_commitment_view_model($this->nvl(array(
            'id' => 'c1', 'name' => 'X', 'amount' => '10.00',
            'active' => '0', 'end_date' => '2020-01-01', 'periodicity' => 'monthly',
        )));
        $html = sticpa_commitment_detail_html($com, $this->defCom());
        $this->assertStringNotContainsString('Hacer una aportación', $html);
        $this->assertStringContainsString('terminó el', $html);
    }

    /**
     * Ni a uno vivo: «Hacer una aportación» creaba un donativo nuevo que no
     * saldaba nada (así salieron los compromisos de más del Foro, 30/09/2026).
     * Lo que se debe se paga desde Pagos o desde la inscripción (plan 041).
     */
    public function testUnCompromisoVivoYaNoOfreceAportar()
    {
        $com = sticpa_commitment_view_model($this->nvl(array(
            'id' => 'c1', 'name' => 'X', 'amount' => '20.00',
            'active' => '1', 'periodicity' => 'monthly',
        )));
        $nvl = $com['nvl'];
        $nvl->pending_annualized_fee = (object) array('value' => '80.00');
        $html = sticpa_commitment_detail_html($com, $this->defCom());

        $this->assertStringNotContainsString('Hacer una aportación', $html);
        $this->assertStringNotContainsString('single_stic_payment_form', $html);
        $this->assertStringContainsString('list_stic_payments', $html);
    }

    /**
     * "Total del año / Aportado / Pendiente" eran tres cajas más un aviso: la
     * misma cuenta cuatro veces. Ahora es UNA barra.
     */
    public function testElAnoEnCursoEsUnaBarraYNoTresCajas()
    {
        $com = sticpa_commitment_view_model($this->nvl(array(
            'id' => 'c1', 'name' => 'X', 'amount' => '20.00', 'active' => '1',
            'periodicity' => 'monthly',
        )));
        $com['nvl']->annualized_fee = (object) array('value' => '240.00');
        $com['nvl']->paid_annualized_fee = (object) array('value' => '160.00');
        $com['nvl']->pending_annualized_fee = (object) array('value' => '80.00');

        $html = sticpa_commitment_detail_html($com, $this->defCom());
        $this->assertStringContainsString('stic-rec-progress', $html);
        $this->assertStringContainsString('width:67%', $html);
        $this->assertStringNotContainsString('Total del año', $html);
        $this->assertStringNotContainsString('Aportado', $html);
    }

    /** Los activos primero: son los que siguen costando dinero. */
    public function testLosCompromisosActivosVanPrimero()
    {
        $html = sticpa_commitments_list_html(array(
            $this->row(array('id' => 'a', 'name' => 'El terminado', 'amount' => '1.00',
                'active' => '0', 'end_date' => '2020-01-01', 'first_payment_date' => '2019-01-01')),
            $this->row(array('id' => 'b', 'name' => 'El activo', 'amount' => '2.00',
                'active' => '1', 'first_payment_date' => '2018-01-01')),
        ), $this->defCom());
        $this->assertLessThan(strpos($html, 'El terminado'), strpos($html, 'El activo'));
    }

    /** El importe y la periodicidad van juntos: por separado no dicen nada. */
    public function testElImporteConSuPeriodicidad()
    {
        $this->assertSame('20,00 € · Mensual', sticpa_commitment_amount_line('20,00 €', 'Mensual'));
        $this->assertSame('20,00 €', sticpa_commitment_amount_line('20,00 €', ''));
        $this->assertSame('Mensual', sticpa_commitment_amount_line('', 'Mensual'));
    }

    /** Los dos estados vacíos explican qué se vería aquí. */
    public function testLosEstadosVacios()
    {
        $this->assertStringContainsString('stic-empty-state', sticpa_payments_list_html(array(), array()));
        $this->assertStringContainsString('stic-empty-state', sticpa_commitments_list_html(array(), array()));
    }

    /**
     * En la ficha de un participante, un compromiso que paga su familia se ve
     * ENTERO —cuenta incluida, ya enmascarada como para todo el mundo— y además
     * dice quién lo paga.
     *
     * Se probó lo contrario (esconder el banco cuando el titular es otro) y el
     * propietario lo tumbó: obligaba a mirar el dinero en la ficha del adulto y
     * lo demás en la del niño, y con padres separados lo que hace falta es que
     * cualquiera de los dos pueda saber cómo se pagó algo y pagarlo.
     */
    public function testEnLaFichaDelNinoElCompromisoSeVeEnteroYDiceQuienLoPaga()
    {
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'participante';
        $_SESSION['scp_user_id'] = 'hijo-1';

        $com = sticpa_commitment_view_model($this->nvl(array(
            'id' => 'c1', 'name' => 'Cuota de socio', 'amount' => '20.00',
            'active' => '1', 'periodicity' => 'monthly', 'payment_method' => 'direct_debit',
            'bank_account' => 'ES1200491234567890123456',
            'stic_payment_commitments_contacts_name' => 'Messeguer, Marta',
            'stic_payment_commitments_contactscontacts_ida' => 'madre-1',
        )));
        $html = sticpa_commitment_detail_html($com, $this->defCom() + array(
            'payment_method' => array('options' => array('direct_debit' => array('value' => 'Domiciliación bancaria'))),
        ));

        $this->assertStringContainsString('Lo paga', $html);
        $this->assertStringContainsString('Messeguer, Marta', $html);
        // Se ve TODO: cuenta (enmascarada) y forma de pago.
        $this->assertStringContainsString('3456', $html);
        $this->assertStringContainsString('Domiciliación bancaria', $html);
        // Y ya no se ofrece «aportar» desde aquí (plan 041).
        $this->assertStringNotContainsString('Hacer una aportación', $html);

        unset($GLOBALS['__stic_filters']['sticpa_profile_audience']);
    }

    /** Si el titular es el propio participante, no hay nada que aclarar. */
    public function testSiElTitularEsElPropioParticipanteNoSeDiceNada()
    {
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'participante';
        $_SESSION['scp_user_id'] = 'yo-1';

        $com = sticpa_commitment_view_model($this->nvl(array(
            'id' => 'c1', 'name' => 'Cuota', 'amount' => '20.00', 'active' => '1',
            'stic_payment_commitments_contacts_name' => 'Yo Mismo',
            'stic_payment_commitments_contactscontacts_ida' => 'yo-1',
        )));
        $this->assertFalse(sticpa_commitment_lo_paga_otra_persona($com));
        $this->assertStringNotContainsString('Lo paga', sticpa_commitment_detail_html($com, $this->defCom()));

        unset($GLOBALS['__stic_filters']['sticpa_profile_audience']);
    }
}
