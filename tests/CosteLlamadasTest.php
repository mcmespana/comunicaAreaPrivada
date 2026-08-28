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
            // Monitores hace falta AQUÍ: era la pantalla más lenta de todas y
            // nadie le contaba las llamadas.
            'single_stic_pasar_lista_monitores' => array('__coord' => 'COM'),
            // Y la ficha de un monitor, que ahora enseña el seguimiento
            // completo, sus grupos y el histórico: todo lo nuevo sale de
            // cargadores de colección, así que su coste NO puede depender de
            // cuántos grupos ni cuántos cursos tenga detrás.
            'single_stic_pasar_lista_monitor' => array('__coord' => 'COM', 'monitor' => 'm1'),
        );

        $lineas = array();
        foreach ($modos as $etiqueta => $sinEnlaces) {
            $lineas[] = '--- ' . $etiqueta;
            foreach ($pantallas as $page => $req) {
                $this->setUp();
                $this->scp->sinEnlaces = $sinEnlaces;
                if (isset($req['__coord'])) {
                    $this->scp->coordEtapa = $req['__coord'];
                    unset($req['__coord']);
                }
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
        /* Los topes BAJARON el 28/08/2026 al juntar los dos cargadores que
         * preguntaban lo mismo (los eventos de la delegación, y las relaciones
         * de quien está conectado). Se bajan a la vez que se arregla: un tope
         * que se queda holgado deja de proteger nada. */
        $topes = array(
            'single_stic_pasar_lista' => array(array(), 8),
            'single_stic_pasar_lista_grupos' => array(array(), 7),
            'single_stic_pasar_lista_marcar' => array(array('grupo' => 'g1'), 9),
            'single_stic_pasar_lista_resumen' => array(array(), 9),
            // Monitores: el tope importa MÁS que en las demás. Su coste no
            // puede depender de cuántos grupos haya en el CRM (hay ~150, casi
            // todos históricos), y eso es justo lo que pasaba: el respaldo por
            // grupo se disparaba en cada grupo vacío.
            'single_stic_pasar_lista_monitores' => array(array('__coord' => 'COM'), 11),
            // La ficha del monitor. Sube respecto a la versión de antes porque
            // ahora lee TAMBIÉN el evento de reuniones, sus sesiones, sus
            // inscripciones y las asistencias de esa persona a las dos cosas:
            // son datos nuevos en la pantalla, no consultas repetidas. Lo que
            // este tope protege es que el histórico y los grupos sigan
            // costando CERO: si alguien mete un `foreach` con una consulta
            // dentro, salta aquí.
            'single_stic_pasar_lista_monitor' => array(array('__coord' => 'COM', 'monitor' => 'm1'), 15),
        );

        foreach ($topes as $page => $spec) {
            list($req, $tope) = $spec;
            $this->setUp();
            if (isset($req['__coord'])) {
                $this->scp->coordEtapa = $req['__coord'];
                unset($req['__coord']);
            }
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
