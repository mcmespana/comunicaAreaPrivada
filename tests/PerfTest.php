<?php
use PHPUnit\Framework\TestCase;

/**
 * Velocidad del área (plan 042): lo que se aparta de sus páginas y la medida
 * de cada petición. Ver inc/stic-perf.php.
 */
class PerfTest extends TestCase
{
    /**
     * VEL-1: la capa de los formularios públicos no viaja con el área. Si
     * alguien cambia el handle con que la encolan, esto no lo ve (es de fuera
     * del repo), pero sí que nadie la quite de la lista por despiste.
     */
    public function test_la_capa_de_los_formularios_publicos_se_aparta_del_area(): void
    {
        $assets = sticpa_area_foreign_assets();
        $this->assertContains('crm-comunica-estilos', $assets['styles']);
        $this->assertContains('crm-comunica-script', $assets['scripts']);
    }

    /**
     * VEL-7: fuera las Inter que no son la del área (la de Elementor sale del
     * dominio provisional de Hostinger) y Google Fonts. Lato solo en la app:
     * en el navegador es la letra del pie de la web.
     */
    public function test_las_fuentes_ajenas_se_apartan_y_lato_solo_en_la_app(): void
    {
        $web = sticpa_area_foreign_assets(false);
        $app = sticpa_area_foreign_assets(true);
        foreach (array($web, $app) as $assets) {
            $this->assertContains('elementor-gf-local-inter', $assets['styles']);
            $this->assertContains('astra-google-fonts', $assets['styles']);
            $this->assertContains('crm-comunica-estilos', $assets['styles']);
        }
        $this->assertNotContains('elementor-gf-local-lato', $web['styles']);
        $this->assertContains('elementor-gf-local-lato', $app['styles']);
    }

    public function test_sin_google_fonts_fuera_sus_preconnect(): void
    {
        $hints = array(
            '//fonts.googleapis.com',
            array('href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'),
            'https://fonts.googleapis.com',
            'https://comunica.movimientoconsolacion.com',
            array('href' => 'https://cdn.example.org'),
        );
        $this->assertSame(
            array('https://comunica.movimientoconsolacion.com', array('href' => 'https://cdn.example.org')),
            sticpa_strip_google_font_hints($hints)
        );
    }

    /** El área sigue trayendo SU Inter, precargada: es lo que hace seguro lo de arriba. */
    public function test_el_area_trae_su_propia_inter_de_100_a_900(): void
    {
        $base = file_get_contents(dirname(__DIR__) . '/css/stic-base.css');
        $this->assertMatchesRegularExpression("/@font-face\s*\{[^}]*font-family:\s*'Inter'[^}]*font-weight:\s*100 900[^}]*inter-latin-var\.woff2/s", $base);
        $this->assertDoesNotMatchRegularExpression('#(url\(|@import)[^;)]*fonts\.(googleapis|gstatic)#', $base);
    }

    /**
     * Lo que hace seguro apartarla: el área no usa nada suyo. Ni sus tokens
     * (--mcm-*) ni su armazón (.crm-profile-app). Si un día el área empieza a
     * usar algo de esa hoja, hay que traerlo aquí (a custom-style.css) en vez
     * de volver a cargarla entera.
     *
     * Su JS tampoco hacía falta, al revés: el formulario de pagar con tarjeta
     * lleva `id="WebToLeadForm"` (lo pide el formulario web de SinergiaCRM), y
     * ese JS arrancaba sobre él su motor de ALTA (validación, borradores en
     * localStorage, estado de envío). Sin él, ese formulario solo lo mueve su
     * propio script, que es para lo que se escribió.
     */
    public function test_el_area_no_depende_de_la_capa_de_los_formularios(): void
    {
        $raiz = dirname(__DIR__);
        $ficheros = array_merge(
            glob($raiz . '/css/*.css'),
            glob($raiz . '/js/*.js'),
            glob($raiz . '/pages/*.php'),
            glob($raiz . '/inc/*.php'),
            array($raiz . '/menu.php')
        );
        foreach ($ficheros as $f) {
            if (basename($f) === 'stic-perf.php') {
                continue; // aquí se nombran a propósito
            }
            $src = file_get_contents($f);
            $this->assertStringNotContainsString('var(--mcm-', $src, basename($f) . ' usa un token de la hoja de formularios');
            $this->assertStringNotContainsString('crm-profile-app', $src, basename($f) . ' usa el armazón de los formularios');
        }
    }
}
