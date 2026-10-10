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

    /* ---- VEL-10: Server-Timing ------------------------------------------ */

    /** La forma exacta: es lo que lee el navegador y lo que se mira en Network → Timing. */
    public function test_server_timing_dice_el_crm_y_el_total(): void
    {
        $this->assertSame(
            'crm;dur=812.4;desc="CRM (9 llamadas)", total;dur=1234.5;desc="Servidor (total)"',
            sticpa_server_timing_value(9, 812.42, 1234.48)
        );
        $this->assertSame(
            'crm;dur=0.0;desc="CRM (1 llamada)", total;dur=35.0;desc="Servidor (total)"',
            sticpa_server_timing_value(1, 0, 35)
        );
    }

    /**
     * Punto decimal aunque la locale sea de coma: con «812,4» el navegador
     * tira la métrica sin avisar. (Si el sistema no tiene ninguna de estas
     * locales, la comprobación vale igual: es la de siempre.)
     */
    public function test_server_timing_con_punto_decimal_en_cualquier_locale(): void
    {
        $antes = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, 'es_ES.UTF-8', 'es_ES', 'de_DE.UTF-8', 'fr_FR.UTF-8');
        try {
            $v = sticpa_server_timing_value(9, 812.42, 1234.48);
        } finally {
            setlocale(LC_NUMERIC, $antes);
        }
        $this->assertStringContainsString('crm;dur=812.4;', $v);
        $this->assertStringContainsString('total;dur=1234.5;', $v);
        // Y donde no hay locale de coma con que probarlo, que nadie vuelva a %f.
        $src = file_get_contents(dirname(__DIR__) . '/inc/stic-perf.php');
        $this->assertDoesNotMatchRegularExpression('/dur=%\.1f/', $src);
    }

    /** Lo que cuenta es lo de ESTA petición: los contadores del cliente del CRM y su inicio. */
    public function test_server_timing_lleva_lo_de_esta_peticion(): void
    {
        $guardado = array($_SESSION ?? null, $_SERVER['REQUEST_TIME_FLOAT'] ?? null,
            SugarRestApiCall::$callCount, SugarRestApiCall::$callMs);
        try {
            $_SESSION = array('scp_user_id' => 'c1');
            $_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
            SugarRestApiCall::$callCount = 9;
            SugarRestApiCall::$callMs = 812.4;
            $this->assertSame(
                'Server-Timing: crm;dur=812.4;desc="CRM (9 llamadas)", total;dur=1500.0;desc="Servidor (total)"',
                sticpa_server_timing_header(1001.5)
            );

            // Sin sesión no hay CRM que medir: nada.
            $_SESSION = array();
            $this->assertSame('', sticpa_server_timing_header(1001.5));
        } finally {
            list($_SESSION, $_SERVER['REQUEST_TIME_FLOAT'], SugarRestApiCall::$callCount, SugarRestApiCall::$callMs) = $guardado;
        }
    }

    /**
     * La cabecera tiene que salir ANTES que el HTML, y las llamadas al CRM se
     * hacen mientras se pinta: por eso el búfer, solo en el área, abierto en
     * `template_redirect` y que deja pasar la página tal cual.
     */
    public function test_el_bufer_solo_en_el_area_y_la_pagina_pasa_tal_cual(): void
    {
        $nivel = ob_get_level();
        $this->assertFalse(sticpa_server_timing_start(false), 'fuera del área no se retiene nada');
        $this->assertSame($nivel, ob_get_level());

        $GLOBALS['__stic_filters']['sticpa_server_timing'] = false;
        try {
            $this->assertFalse(sticpa_server_timing_start(true), 'el filtro lo apaga');
        } finally {
            unset($GLOBALS['__stic_filters']['sticpa_server_timing']);
        }
        $this->assertSame($nivel, ob_get_level());

        ob_start();
        $this->assertTrue(sticpa_server_timing_start(true));
        $estado = ob_get_status();
        $this->assertSame('sticpa_server_timing_flush', $estado['name']);
        echo '<!doctype html><title>Área</title>';
        ob_end_flush();
        $this->assertSame('<!doctype html><title>Área</title>', ob_get_clean());
        $this->assertSame($nivel, ob_get_level());

        $src = file_get_contents(dirname(__DIR__) . '/inc/stic-perf.php');
        $this->assertMatchesRegularExpression("/add_action\('template_redirect', 'sticpa_server_timing_start', 0\)/", $src);
    }
}
