<?php
use PHPUnit\Framework\TestCase;

/**
 * Resolución del tema claro/oscuro del área (inc/stic-theme.php).
 *
 * Lo que se fija aquí es el CONTRATO de prioridades, que es la parte fácil de
 * romper sin darse cuenta:
 *
 *   · Dentro de la app MCM (?app=1 → cookie sticpa_app) manda SIEMPRE la app:
 *     ?theme= en la primera carga y la cookie mcm_theme después. La preferencia
 *     propia del área se ignora ahí (la app ya tiene su selector y no puede
 *     haber dos interruptores peleándose).
 *   · En navegador manda la cookie propia sticpa_theme, y si no hay, 'auto'
 *     (lo resuelve el dispositivo en el cliente).
 *   · Cualquier valor que no sea exactamente 'light' o 'dark' se descarta:
 *     tanto el query param como la cookie son entrada de usuario.
 */
final class ThemeTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = array();
        $_COOKIE = array();
    }

    protected function tearDown(): void
    {
        $_GET = array();
        $_COOKIE = array();
    }

    // ---- Navegador (fuera de la app) ------------------------------------

    public function test_navegador_sin_preferencia_es_auto(): void
    {
        $this->assertSame('auto', sticpa_theme_pref());
    }

    public function test_navegador_respeta_la_cookie_propia(): void
    {
        $_COOKIE['sticpa_theme'] = 'dark';
        $this->assertSame('dark', sticpa_theme_pref());

        $_COOKIE['sticpa_theme'] = 'light';
        $this->assertSame('light', sticpa_theme_pref());
    }

    public function test_navegador_ignora_el_tema_que_manda_la_app(): void
    {
        // Sin cookie sticpa_app no estamos en la WebView: mcm_theme no pinta nada.
        $_COOKIE['mcm_theme'] = 'dark';
        $this->assertSame('auto', sticpa_theme_pref());
    }

    // ---- Dentro de la app MCM (?app=1) ----------------------------------

    public function test_app_manda_por_query_en_la_primera_carga(): void
    {
        $_COOKIE['sticpa_app'] = '1';
        $_GET['theme'] = 'dark';
        $this->assertSame('dark', sticpa_theme_pref());
    }

    public function test_app_manda_por_cookie_en_las_siguientes(): void
    {
        $_COOKIE['sticpa_app'] = '1';
        $_COOKIE['mcm_theme'] = 'dark';
        $this->assertSame('dark', sticpa_theme_pref());
    }

    public function test_app_el_query_gana_a_la_cookie(): void
    {
        // La app manda ?theme= al abrir; si difiere de la cookie, el query es más nuevo.
        $_COOKIE['sticpa_app'] = '1';
        $_COOKIE['mcm_theme'] = 'light';
        $_GET['theme'] = 'dark';
        $this->assertSame('dark', sticpa_theme_pref());
    }

    public function test_app_gana_a_la_preferencia_propia_del_area(): void
    {
        $_COOKIE['sticpa_app'] = '1';
        $_COOKIE['sticpa_theme'] = 'dark';   // preferencia guardada en la web
        $_COOKIE['mcm_theme'] = 'light';     // lo que dice la app AHORA
        $this->assertSame('light', sticpa_theme_pref());
    }

    public function test_app_en_modo_sistema_deja_decidir_al_dispositivo(): void
    {
        // La app en "Sistema" no manda tema: 'auto' y lo resuelve matchMedia.
        $_COOKIE['sticpa_app'] = '1';
        $this->assertSame('auto', sticpa_theme_pref());
    }

    // ---- Saneado (el valor viene del navegador) --------------------------

    #[PHPUnit\Framework\Attributes\DataProvider('valoresInvalidos')]
    public function test_valores_invalidos_caen_en_auto(string $valor): void
    {
        $_COOKIE['sticpa_theme'] = $valor;
        $this->assertSame('auto', sticpa_theme_pref());

        $_COOKIE = array('sticpa_app' => '1');
        $_GET['theme'] = $valor;
        $this->assertSame('auto', sticpa_theme_pref());
    }

    public static function valoresInvalidos(): array
    {
        return array(
            'inyección de HTML' => array('"><script>alert(1)</script>'),
            'mayúsculas'        => array('DARK'),
            'con espacios'      => array(' dark '),
            'inventado'         => array('sepia'),
            'vacío'             => array(''),
        );
    }

    // ---- Atributos que se vuelcan al HTML -------------------------------

    public function test_atributos_con_preferencia_explicita_traen_el_esquema_resuelto(): void
    {
        $_COOKIE['sticpa_theme'] = 'dark';
        $attr = sticpa_theme_attr();
        $this->assertStringContainsString("data-stic-theme='dark'", $attr);
        // Con preferencia explícita el servidor ya sabe qué pintar: cero parpadeo.
        $this->assertStringContainsString("data-stic-scheme='dark'", $attr);
    }

    public function test_atributos_en_auto_dejan_el_esquema_al_cliente(): void
    {
        $attr = sticpa_theme_attr();
        $this->assertStringContainsString("data-stic-theme='auto'", $attr);
        // El servidor NO puede saber la apariencia del dispositivo: lo resuelve
        // el script inline de <head> antes del primer pintado.
        $this->assertStringNotContainsString('data-stic-scheme', $attr);
    }

    // ---- Control de apariencia ------------------------------------------

    public function test_el_control_no_se_pinta_dentro_de_la_app(): void
    {
        $_COOKIE['sticpa_app'] = '1';
        $this->assertFalse(sticpa_theme_switch_enabled());
        $this->assertSame('', sticpa_appearance_switch_html());
    }

    public function test_el_control_se_pinta_en_navegador_con_el_segmento_activo(): void
    {
        $_COOKIE['sticpa_theme'] = 'dark';
        $html = sticpa_appearance_switch_html();
        $this->assertTrue(sticpa_theme_switch_enabled());
        $this->assertStringContainsString("data-stic-theme-set='dark' aria-pressed='true'", $html);
        $this->assertStringContainsString("data-stic-theme-set='auto' aria-pressed='false'", $html);
        $this->assertStringContainsString("data-stic-theme-set='light' aria-pressed='false'", $html);
    }

    public function test_en_auto_el_segmento_activo_es_auto(): void
    {
        $html = sticpa_appearance_switch_html();
        $this->assertStringContainsString("data-stic-theme-set='auto' aria-pressed='true'", $html);
    }
}
