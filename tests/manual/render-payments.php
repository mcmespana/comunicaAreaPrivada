<?php
/**
 * ARNÉS DE RENDER OFFLINE de Pagos y Compromisos de pago.
 * design.md §9: una pantalla no está hecha hasta que se ha capturado a 375px y
 * se ha mirado, en los dos temas.
 *
 *   php tests/manual/render-payments.php > /tmp/pagos.html
 *
 * Los casos que se pintan son los que hacen daño: el recibo devuelto (que antes
 * se leía igual que uno cobrado), el compromiso activo con pendiente, el
 * terminado, y los dos estados vacíos.
 */

require_once __DIR__ . '/../bootstrap.php';
$B = dirname(__DIR__, 2) . '/';
require_once $B . 'inc/stic-record-view.php';
require_once $B . 'inc/stic-formatter.php';
require_once $B . 'inc/stic-payments.php';

function stic_nvl(array $a)
{
    $o = new stdClass();
    foreach ($a as $k => $v) {
        $o->$k = (object) array('value' => $v);
    }
    return $o;
}

function stic_row(array $a)
{
    $r = new stdClass();
    $r->name_value_list = stic_nvl($a);
    return $r;
}

$defPagos = array(
    'status' => array('options' => array(
        'settled'  => array('value' => 'Cobrado'),
        'pending'  => array('value' => 'Pendiente'),
        'returned' => array('value' => 'Devuelto'),
    )),
    'payment_method' => array('options' => array(
        'direct_debit' => array('value' => 'Domiciliación bancaria'),
        'card'         => array('value' => 'Tarjeta'),
        'bizum'        => array('value' => 'Bizum'),
    )),
    'payment_type' => array('options' => array(
        'quota'    => array('value' => 'Cuota'),
        'donation' => array('value' => 'Donación'),
    )),
    'sepa_rejected_reason' => array('options' => array(
        'AC04' => array('value' => 'la cuenta está cancelada'),
    )),
);

$defCom = array(
    'periodicity' => array('options' => array(
        'monthly'  => array('value' => 'Mensual'),
        'annual'   => array('value' => 'Anual'),
        'punctual' => array('value' => 'Puntual'),
    )),
    'payment_method' => $defPagos['payment_method'],
    'destination' => array('options' => array('general' => array('value' => 'Fondo general'))),
);

$pagos = array(
    stic_row(array('id' => 'p1', 'name' => 'Cuota de agosto', 'amount' => '20.00',
        'payment_date' => '2026-08-01', 'status' => 'settled',
        'payment_type' => 'quota', 'payment_method' => 'direct_debit')),
    stic_row(array('id' => 'p2', 'name' => 'Campamento de verano 2026 — 1.º plazo', 'amount' => '120.00',
        'payment_date' => '2026-03-14', 'status' => 'returned',
        'payment_type' => 'quota', 'payment_method' => 'direct_debit')),
    stic_row(array('id' => 'p3', 'name' => 'Donación puntual', 'amount' => '1250.00',
        'payment_date' => '2026-05-20', 'status' => 'pending',
        'payment_type' => 'donation', 'payment_method' => 'bizum')),
);

$compromisos = array(
    stic_row(array('id' => 'c1', 'name' => 'Cuota de socio', 'amount' => '20.00',
        'periodicity' => 'monthly', 'payment_method' => 'direct_debit',
        'first_payment_date' => '2024-01-01', 'active' => '1')),
    stic_row(array('id' => 'c2', 'name' => 'Apadrinamiento', 'amount' => '300.00',
        'periodicity' => 'annual', 'payment_method' => 'card',
        'first_payment_date' => '2022-09-15', 'end_date' => '2025-09-15', 'active' => '0')),
);

$fichaDevuelto = sticpa_payment_detail_html(sticpa_payment_view_model(stic_nvl(array(
    'id' => 'p2', 'name' => 'Campamento de verano 2026 — 1.º plazo', 'amount' => '120.00',
    'payment_date' => '2026-03-14', 'status' => 'returned',
    'payment_type' => 'quota', 'payment_method' => 'direct_debit',
    'bank_account' => 'ES1200491234567890123456', 'banking_concept' => 'MCM Campamento 2026 1/3',
    'rejection_date' => '2026-03-20', 'sepa_rejected_reason' => 'AC04',
    'transaction_code' => '884213',
    'stic_payments_stic_registrations_name' => 'Campamento de verano 2026',
))), $defPagos);

$fichaCobrado = sticpa_payment_detail_html(sticpa_payment_view_model(stic_nvl(array(
    'id' => 'p1', 'name' => 'Cuota de agosto', 'amount' => '20.00',
    'payment_date' => '2026-08-01', 'status' => 'settled',
    'payment_type' => 'quota', 'payment_method' => 'direct_debit',
    'bank_account' => 'ES1200491234567890123456',
    'stic_payments_stic_payment_commitments_name' => 'Cuota de socio',
))), $defPagos);

$fichaComActivo = sticpa_commitment_detail_html(sticpa_commitment_view_model(stic_nvl(array(
    'id' => 'c1', 'name' => 'Cuota de socio', 'amount' => '20.00',
    'periodicity' => 'monthly', 'payment_method' => 'direct_debit',
    'first_payment_date' => '2024-01-01', 'active' => '1',
    'bank_account' => 'ES1200491234567890123456', 'banking_concept' => 'MCM Cuota socio',
    'annualized_fee' => '240.00', 'paid_annualized_fee' => '160.00', 'pending_annualized_fee' => '80.00',
    'destination' => 'general',
))), $defCom);

$fichaComTerminado = sticpa_commitment_detail_html(sticpa_commitment_view_model(stic_nvl(array(
    'id' => 'c2', 'name' => 'Apadrinamiento', 'amount' => '300.00',
    'periodicity' => 'annual', 'payment_method' => 'card',
    'first_payment_date' => '2022-09-15', 'end_date' => '2025-09-15', 'active' => '0',
))), $defCom);

$listaPagos  = sticpa_payments_list_html($pagos, $defPagos);
$listaCom    = sticpa_commitments_list_html($compromisos, $defCom);
$vacioPagos  = sticpa_payments_list_html(array(), $defPagos);
$vacioCom    = sticpa_commitments_list_html(array(), $defCom);

$css = file_get_contents($B . 'css/custom-style.css');

echo "<!doctype html><html lang=es data-stic-scheme=light><head><meta charset=utf-8>
<meta name=viewport content='width=device-width,initial-scale=1'><title>Pagos y compromisos</title>
<style>{$css}</style><style>body{margin:0;background:var(--bg-color,#f6f7f9)}.harness{padding:1rem}
.harness h2{font:700 .8rem/1 system-ui;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:2rem 0 .75rem}
.harness h2:first-child{margin-top:0}</style></head><body>
<div class='stic-container'><div class='stic-tab-content'><div class=harness>
<h2>Pagos — listado</h2>{$listaPagos}
<h2>Pagos — vacío</h2>{$vacioPagos}
<h2>Ficha de un recibo devuelto</h2>{$fichaDevuelto}
<h2>Ficha de un recibo cobrado</h2>{$fichaCobrado}
<h2>Compromisos — listado</h2>{$listaCom}
<h2>Compromisos — vacío</h2>{$vacioCom}
<h2>Ficha de compromiso activo</h2>{$fichaComActivo}
<h2>Ficha de compromiso terminado</h2>{$fichaComTerminado}
</div></div></div></body></html>";
