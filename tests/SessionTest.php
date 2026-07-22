<?php
use PHPUnit\Framework\TestCase;

/**
 * Caracterización del montaje de sesión desde un registro del CRM
 * (inc/stic-magic-login.php::sticpa_establish_session). No llama al CRM:
 * recibe un objeto entry ya cargado. Con RELATIONSHIP_TUTOR_TYPES sin definir,
 * scp_user_adult = true y no se consulta nada externo.
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
        ), 'C-42');

        sticpa_establish_session($e, 'Contacts');

        $this->assertSame('Contacts', $_SESSION['scp_module']);
        $this->assertSame('C-42', $_SESSION['scp_user_id']);
        $this->assertSame('Pérez, Ana', $_SESSION['scp_user_contact_name']);
        $this->assertSame('ACC-7', $_SESSION['scp_account_id']);
        $this->assertSame('12345678Z', $_SESSION['scp_user_account_name']);
        $this->assertSame('U-3', $_SESSION['scp_user_assigned_user_id']);
        $this->assertTrue($_SESSION['scp_user_adult']); // sin RELATIONSHIP_TUTOR_TYPES
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $e = $this->entry(array('name' => array('value' => 'Solo Nombre')), 'C-1');
        sticpa_establish_session($e, 'Accounts');

        $this->assertSame('Solo Nombre', $_SESSION['scp_user_contact_name']);
        $this->assertNull($_SESSION['scp_account_id']);
        $this->assertNull($_SESSION['scp_user_account_name']);
        $this->assertNull($_SESSION['scp_user_assigned_user_id']);
    }
}
