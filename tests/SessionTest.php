<?php
use PHPUnit\Framework\TestCase;

/**
 * Caracterización del montaje de sesión desde un registro del CRM
 * (inc/stic-magic-login.php::sticpa_establish_session). No llama al CRM:
 * recibe un objeto entry ya cargado. Con RELATIONSHIP_TUTOR_TYPES sin definir,
 * scp_user_adult = true y no se consulta nada externo.
 *
 * Los entries llevan `stic_relationship_type_c` A PROPÓSITO, y no es decorado:
 * `sticpa_establish_session` resuelve el rol, y si el campo no viene en el
 * entry lo va a buscar al CRM. Con el campo puesto —que es lo que trae un
 * login real— se resuelve en memoria y el test sigue sin tocar la red.
 */
final class SessionTest extends TestCase
{
    protected function setUp(): void { $_SESSION = array(); }

    private function entry(array $nvl, string $id = 'ID-1'): object
    {
        $obj = new stdClass();
        $obj->id = $id;
        $obj->name_value_list = json_decode(json_encode($nvl));
        return $obj;
    }

    public function test_contact_session_is_built(): void
    {
        $e = $this->entry(array(
            'name' => array('value' => 'Pérez, Ana'),
            'account_id' => array('value' => 'ACC-7'),
            'stic_pa_username_c' => array('value' => '12345678Z'),
            'assigned_user_id' => array('value' => 'U-3'),
            'stic_relationship_type_c' => array('value' => '^grupo^,^monitor^'),
        ), 'C-42');

        sticpa_establish_session($e, 'Contacts');

        $this->assertSame('Contacts', $_SESSION['scp_module']);
        $this->assertSame('C-42', $_SESSION['scp_user_id']);
        $this->assertSame('Pérez, Ana', $_SESSION['scp_user_contact_name']);
        $this->assertSame('ACC-7', $_SESSION['scp_account_id']);
        $this->assertSame('12345678Z', $_SESSION['scp_user_account_name']);
        $this->assertSame('U-3', $_SESSION['scp_user_assigned_user_id']);
        $this->assertTrue($_SESSION['scp_user_adult']); // sin RELATIONSHIP_TUTOR_TYPES
        // El rol forma parte de lo que monta la sesión, y queda marcado como
        // RESUELTO: es lo que evita que se vuelva a preguntar en cada página.
        $this->assertSame('monitor', $_SESSION['scp_role']);
        $this->assertTrue($_SESSION['scp_role_resolved']);
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $e = $this->entry(array(
            'name' => array('value' => 'Solo Nombre'),
            'stic_relationship_type_c' => array('value' => ''),
        ), 'C-1');
        sticpa_establish_session($e, 'Accounts');

        $this->assertSame('Solo Nombre', $_SESSION['scp_user_contact_name']);
        $this->assertNull($_SESSION['scp_account_id']);
        $this->assertNull($_SESSION['scp_user_account_name']);
        $this->assertNull($_SESSION['scp_user_assigned_user_id']);
        // Sin tipo de relación: rol vacío, pero RESUELTO (el dato es bueno).
        $this->assertSame('', $_SESSION['scp_role']);
        $this->assertTrue($_SESSION['scp_role_resolved']);
    }
}
