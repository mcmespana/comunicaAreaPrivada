<?php
use PHPUnit\Framework\TestCase;

/**
 * Caracterización del acceso mágico firmado con HMAC
 * (inc/stic-magic-login.php: generate/validate + base64url).
 * Es la lógica más sensible: si se rompe, se podrían falsificar accesos.
 */
final class MagicLinkTest extends TestCase
{
    public function test_b64url_roundtrip(): void
    {
        $s = 'Contacts|abc-123|9999999999|deadbeef';
        $this->assertSame($s, sticpa_b64url_decode(sticpa_b64url_encode($s)));
    }

    public function test_b64url_is_url_safe(): void
    {
        $enc = sticpa_b64url_encode(random_bytes(48));
        $this->assertSame(0, preg_match('/[+\/=]/', $enc), 'no debe contener + / =');
    }

    public function test_valid_link_is_accepted(): void
    {
        $url = sticpa_generate_magic_link('https://x.test/area/', 'Contacts', 'C-1', 3600);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertArrayHasKey('acceso_magico', $q);
        $res = sticpa_validate_magic_link($q['acceso_magico']);
        $this->assertSame(array('Contacts', 'C-1'), $res);
    }

    public function test_accounts_module_is_accepted(): void
    {
        $url = sticpa_generate_magic_link('https://x.test/area/', 'Accounts', 'A-9', 3600);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(array('Accounts', 'A-9'), sticpa_validate_magic_link($q['acceso_magico']));
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $url = sticpa_generate_magic_link('https://x.test/area/', 'Contacts', 'C-1', 3600);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $raw = sticpa_b64url_decode($q['acceso_magico']);
        list($m, $id, $exp, $sig) = explode('|', $raw);
        // Manipular el id manteniendo la firma vieja → debe rechazar.
        $forged = sticpa_b64url_encode($m . '|C-999|' . $exp . '|' . $sig);
        $this->assertFalse(sticpa_validate_magic_link($forged));
    }

    public function test_expired_link_is_rejected(): void
    {
        // ttl negativo → exp en el pasado.
        $url = sticpa_generate_magic_link('https://x.test/area/', 'Contacts', 'C-1', -10);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertFalse(sticpa_validate_magic_link($q['acceso_magico']));
    }

    public function test_unknown_module_is_rejected(): void
    {
        $exp = time() + 3600;
        $payload = 'Leads|C-1|' . $exp;
        $sig = hash_hmac('sha256', $payload, sticpa_get_magic_secret());
        $data = sticpa_b64url_encode($payload . '|' . $sig);
        $this->assertFalse(sticpa_validate_magic_link($data));
    }

    public function test_malformed_payload_is_rejected(): void
    {
        // Menos de 4 partes.
        $this->assertFalse(sticpa_validate_magic_link(sticpa_b64url_encode('Contacts|C-1')));
        // Sin separador.
        $this->assertFalse(sticpa_validate_magic_link(sticpa_b64url_encode('nopipes')));
    }
}
