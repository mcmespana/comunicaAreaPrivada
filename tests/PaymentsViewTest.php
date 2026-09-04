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
        // Y se apaga: ya no cuenta como dinero cobrado.
        $this->assertStringContainsString('is-past', $html);
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
            $this->row(array('id' => 'a', 'name' => 'El viejo', 'amount' => '1.00', 'payment_date' => '2020-01-01')),
            $this->row(array('id' => 'b', 'name' => 'El nuevo', 'amount' => '2.00', 'payment_date' => '2026-08-01')),
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

    /** A uno vivo sí, con el importe pendiente ya puesto en el enlace. */
    public function testUnCompromisoVivoOfrecePagarConElImportePuesto()
    {
        $com = sticpa_commitment_view_model($this->nvl(array(
            'id' => 'c1', 'name' => 'X', 'amount' => '20.00',
            'active' => '1', 'periodicity' => 'monthly',
        )));
        $nvl = $com['nvl'];
        $nvl->pending_annualized_fee = (object) array('value' => '80.00');
        $html = sticpa_commitment_detail_html($com, $this->defCom());

        $this->assertStringContainsString('Hacer una aportación', $html);
        $this->assertStringContainsString('amount=80.00', $html);
        // Y se dice lo que es: una aportación puntual, no la liquidación de
        // este compromiso. El formulario de pago no sabe hacer lo segundo.
        $this->assertStringContainsString('aportación puntual', $html);
    }

    /** El importe del enlace de pago se sanea: viaja por la URL. */
    public function testElImporteDelEnlaceDePago()
    {
        $this->assertStringContainsString('amount=20.00', sticpa_commitment_pay_url('20'));
        $this->assertStringNotContainsString('amount=', sticpa_commitment_pay_url('0'));
        $this->assertStringNotContainsString('amount=', sticpa_commitment_pay_url(''));
        $this->assertStringNotContainsString('amount=', sticpa_commitment_pay_url('-5'));
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
}
