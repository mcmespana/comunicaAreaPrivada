<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/PasarListaRenderTest.php';

/**
 * Cuantas llamadas al CRM cuesta cada pantalla.
 *
 * No es un test de exito/fallo estetico: es una MEDIDA. El area privada se usa
 * en una webview con datos moviles un sabado por la tarde, y cada llamada al
 * CRM es un round-trip. Este archivo existe para que el numero no suba sin que
 * nadie se de cuenta.
 */
class CosteLlamadasTest extends TestCase
{
    private $scp;

    protected function setUp(): void
    {
        $GLOBALS['__stic_pl_now'] = mktime(17, 0, 0, 11, 15, 2025);
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_options'] = array();
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array('scp_user_id' => 'm1', 'scp_user_assigned_user_id' => 'deleg-castellon', 'scp_user_contact_name' => 'David Soler');
        $_REQUEST = array();
        $_POST = array();
        $this->scp = new FakeSCP();
        // El modo REALISTA: esta instancia no devuelve enlaces anidados.
    }

    private function render($page)
    {
        $html = '';
        $objSCP = $this->scp;
        $pageSettings = array();
        if (!defined('ABSPATH')) { define('ABSPATH', '/'); }
        require __DIR__ . '/../pages/' . $page . '.php';
        return $html;
    }

    public function testCosteDeCadaPantalla()
    {
        $modos = array('enlaces OK' => false, 'sin enlaces (respaldo)' => true);

        $pantallas = array(
            'single_stic_pasar_lista' => array(),
            'single_stic_pasar_lista_grupos' => array(),
            'single_stic_pasar_lista_marcar' => array('grupo' => 'g1'),
            'single_stic_pasar_lista_resumen' => array(),
            'single_stic_pasar_lista_ficha' => array('persona' => 'c1'),
        );

        $lineas = array();
        foreach ($modos as $etiqueta => $sinEnlaces) {
            $lineas[] = '--- ' . $etiqueta;
            foreach ($pantallas as $page => $req) {
                $this->setUp();
                $this->scp->sinEnlaces = $sinEnlaces;
                $_REQUEST = $req;
                $this->render($page);
                $n = count($this->scp->calls);
                $lineas[] = sprintf('%-34s %3d llamadas', str_replace('single_stic_pasar_lista', 'PL', $page), $n);
                $cuenta = array_count_values($this->scp->calls);
                arsort($cuenta);
                foreach ($cuenta as $q => $veces) {
                    $lineas[] = sprintf('      %2dx %s', $veces, $q);
                }
            }
        }
        fwrite(STDERR, "\n" . implode("\n", $lineas) . "\n");
        $this->assertTrue(true);
    }

    /**
     * TOPES. Aqui si se falla: son los numeros que costo bajar y no pueden
     * subir sin que alguien lo vea.
     *
     * El margen sobre el numero real es de una o dos llamadas, no del doble: un
     * tope holgado no protege de nada. Si un cambio los pasa, o esta justificado
     * y se sube el tope a mano, o es una regresion de rendimiento.
     */
    public function testElCosteNoSePasaDeLosTopes()
    {
        $topes = array(
            'single_stic_pasar_lista' => array(array(), 8),
            'single_stic_pasar_lista_grupos' => array(array(), 8),
            'single_stic_pasar_lista_marcar' => array(array('grupo' => 'g1'), 10),
            'single_stic_pasar_lista_resumen' => array(array(), 9),
        );

        foreach ($topes as $page => $spec) {
            list($req, $tope) = $spec;
            $this->setUp();
            $_REQUEST = $req;
            $this->render($page);
            $n = count($this->scp->calls);
            $this->assertLessThanOrEqual(
                $tope,
                $n,
                $page . ' hace ' . $n . ' llamadas al CRM (tope ' . $tope . '). '
                    . 'Cada una cuesta medio segundo largo en movil.'
            );
        }
    }
}
