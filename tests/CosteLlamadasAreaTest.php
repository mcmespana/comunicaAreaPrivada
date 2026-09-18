<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/PasarListaRenderTest.php';

/**
 * CUÁNTAS LLAMADAS AL CRM CUESTAN LAS PANTALLAS DE TODO EL MUNDO.
 * ----------------------------------------------------------------------------
 * `CosteLlamadasTest` mide once pantallas y las once son de Pasar Lista y Mis
 * Grupos: justo la parte que ya se optimizó. La home, Eventos, Inscripciones,
 * Pagos, Compromisos y Documentos —por donde entra una familia, que es el
 * usuario que viene— no las medía nadie. Si el rendimiento es el problema, la
 * medida no puede cubrir solo la mitad que ya está bien.
 *
 * Igual que su hermano: la primera prueba IMPRIME el detalle (no falla nunca) y
 * la segunda pone TOPES que sí fallan. El número que importa no es el total de
 * llamadas sino las TANDAS: diez llamadas en tres tandas paralelas son tres
 * viajes de ida y vuelta, no diez.
 *
 * CÓMO LEER UN NÚMERO DE AQUÍ. Es el coste con la caché FRÍA (cada prueba
 * arranca sin transients), que es el caso que se nota: el primer tap del día,
 * el que se hace con datos móviles en la puerta de un colegio.
 */
class CosteLlamadasAreaTest extends TestCase
{
    private $scp;

    public static function setUpBeforeClass(): void
    {
        // La home pinta sus tarjetas desde el menú y el menú decide qué enseña
        // según quién eres, así que las dos piezas hacen falta de verdad: sin
        // ellas se mediría una home que no es la que ve nadie.
        require_once __DIR__ . '/../menu.php';
        require_once __DIR__ . '/../inc/stic-formatter.php';
        // Los módulos que pintan estas pantallas. Se cargan aquí y no en
        // bootstrap.php porque solo los necesita este test.
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-listController.php';
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/stic-event-audience.php';
        require_once __DIR__ . '/../inc/stic-registrations.php';
        require_once __DIR__ . '/../inc/stic-payments.php';
        require_once __DIR__ . '/../inc/stic-documents.php';
        require_once __DIR__ . '/../inc/stic-equipo.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_pl_now'] = mktime(17, 0, 0, 11, 15, 2025);
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_options'] = array();
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array(
            'scp_user_id' => 'm1',
            'scp_module' => 'Contacts',
            'scp_user_assigned_user_id' => 'deleg-castellon',
            'scp_user_contact_name' => 'David Soler',
            'scp_user_adult' => true,
            // Rol ya resuelto: si no, la primera pantalla se gasta una llamada
            // en preguntarlo y el número diría más de la sesión que de la
            // pantalla.
            'scp_role' => 'monitor',
            'scp_role_resolved' => true,
            'scp_relationship_raw' => '^grupo^,^monitor^',
        );
        $_REQUEST = array();
        $_POST = array();
        $_GET = array();
        $this->scp = new FakeSCP();

        // EL DOBLE TAMBIÉN ES EL OBJETO GLOBAL, y no es un detalle del arnés:
        // no todo el código usa el `$objSCP` que recibe la pantalla. La home,
        // sin ir más lejos, comprueba el certificado de delitos sexuales
        // llamando a `SugarRestApiCall::getObjSCP()` por su cuenta. Si aquí no
        // se enchufara el doble, esa llamada no se contaría —y es justo la
        // clase de llamada que se escapa de las cuentas—.
        SugarRestApiCall::$objSCP = $this->scp;
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
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

    /** Las pantallas que ve cualquiera, con los parámetros DE VERDAD. */
    private function pantallas()
    {
        return array(
            'single_stic_home' => array(),
            'list_stic_events' => array(),
            'list_stic_registrations' => array(),
            'list_stic_payments' => array(),
            'list_stic_payment_commitments' => array(),
            'list_stic_documents' => array(),
        );
    }

    /** Cuenta las llamadas de una pantalla y devuelve [n, tandas]. */
    private function medir($page, $req)
    {
        $this->setUp();
        $_REQUEST = $req;
        $this->render($page);
        return array(count($this->scp->calls), $this->scp->batches);
    }

    public function testCosteDeCadaPantalla()
    {
        $lineas = array();
        foreach ($this->pantallas() as $page => $req) {
            list($n, $tandas) = $this->medir($page, $req);
            $lineas[] = sprintf(
                '%-34s %3d llamadas en %d tandas (%s) + %d sueltas',
                $page,
                $n,
                count($tandas),
                implode('+', $tandas) ?: '-',
                $n - array_sum($tandas)
            );
            $cuenta = array_count_values($this->scp->calls);
            arsort($cuenta);
            foreach ($cuenta as $q => $veces) {
                $lineas[] = sprintf('      %2dx %s', $veces, $q);
            }
        }
        fwrite(STDERR, "\n" . implode("\n", $lineas) . "\n");
        $this->assertTrue(true);
    }

    /**
     * LOS TOPES. Aquí sí se falla.
     *
     * Son los números de HOY, no un objetivo: este archivo nace para que no
     * suban sin que nadie se entere, no para exigir una mejora que todavía no
     * se ha hecho. Si bajas uno, baja también su tope en la misma PR.
     *
     * Si un día uno sube por una razón buena, se sube el número Y se escribe
     * al lado por qué. Lo que no vale es subirlo en silencio.
     */
    public function testNingunaPantallaSeVaDeTope()
    {
        $topes = array(
            // 3: la ficha del contacto (el aviso del certificado de delitos
            // sexuales), las inscripciones y los eventos de la agenda. La home
            // es la pantalla a la que se vuelve TODO EL RATO —es el destino de
            // cada «volver»—, así que es donde más duele cada llamada.
            'single_stic_home' => 3,
            // 3, y eran 4 hasta el 18/09/2026: pedía la definición de campos
            // dos veces con dos listas distintas. Ver sticpa_event_field_definition().
            'list_stic_events' => 3,
            // 2 los cuatro listados: las filas y la definición del desplegable.
            'list_stic_registrations' => 2,
            'list_stic_payments' => 2,
            'list_stic_payment_commitments' => 2,
            'list_stic_documents' => 2,
        );

        foreach ($this->pantallas() as $page => $req) {
            list($n, ) = $this->medir($page, $req);
            $this->assertLessThanOrEqual(
                $topes[$page],
                $n,
                "{$page} hace {$n} llamadas al CRM y el tope es {$topes[$page]}. "
                    . 'Si la subida es inevitable, sube el tope en esta misma PR y di por qué.'
            );
        }
    }

    /**
     * LO QUE ESTA MEDIDA DEJA A LA VISTA: AQUÍ NO HAY TANDAS.
     *
     * Pasar Lista agrupa sus consultas en tandas paralelas (`sticpa_pl_prime`),
     * así que diez llamadas son tres viajes. Estas pantallas NO: hacen sus dos
     * o tres llamadas EN FILA, una esperando a la anterior. Con un CRM lento y
     * datos móviles, tres llamadas en serie son tres esperas sumadas.
     *
     * Esto no falla, se APUNTA: agruparlas es un trabajo aparte y no se hace de
     * rebote en el test que lo descubre. Cuando alguien lo haga, este número
     * sube y esta prueba se convierte en el sitio donde se comprueba.
     */
    public function testHoyNingunaDeEstasPantallasAgrupaSusLlamadas()
    {
        $conTandas = array();
        foreach ($this->pantallas() as $page => $req) {
            list($n, $tandas) = $this->medir($page, $req);
            if (!empty($tandas)) {
                $conTandas[$page] = count($tandas);
            }
        }
        // Si algún día esto falla es una BUENA noticia: alguien ha agrupado.
        // Quita la pantalla de aquí y celébralo.
        $this->assertSame(
            array(),
            $conTandas,
            'Alguien ha agrupado llamadas en ' . implode(', ', array_keys($conTandas))
                . ': actualiza este test, que estaba escrito para cuando no las agrupaba ninguna.'
        );
    }

    /**
     * Y NINGUNA PUEDE HACER UNA LLAMADA POR FILA.
     *
     * El 1+N es el fallo que más veces ha aparecido en este proyecto y el que
     * peor se ve leyendo el código: una llamada dentro de un `foreach` no
     * parece nada hasta que hay treinta filas. Aquí se caza solo: si una misma
     * consulta se repite, es que se está haciendo por elemento.
     */
    public function testNingunaPantallaLlamaUnaVezPorFila()
    {
        foreach ($this->pantallas() as $page => $req) {
            $this->medir($page, $req);
            $repes = array_filter(array_count_values($this->scp->calls), function ($n) {
                return $n > 1;
            });
            $this->assertSame(
                array(),
                $repes,
                "{$page} repite consultas (" . implode(', ', array_keys($repes)) . '): '
                    . 'huele a una llamada por fila. Agrupa en una sola consulta.'
            );
        }
    }
}
