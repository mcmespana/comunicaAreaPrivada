<?php
/**
 * El calentador de caché de Pasar Lista (inc/stic-pasar-lista-warm.php).
 * ----------------------------------------------------------------------------
 * Lo que se prueba es lo que puede hacer daño en silencio:
 *   · que la firma no se pueda saltar (ni con el secreto sin configurar),
 *   · que una petición vieja no valga (repetirla es el ataque obvio),
 *   · que calentar deje la caché HECHA de verdad: es todo el objetivo, y sin
 *     comprobarlo el job saldría verde calentando nada,
 *   · y que la delegación se pueda poner a mano, porque el Guardián llama sin
 *     sesión y sin eso la caché se escribiría bajo la clave de nadie.
 */

use PHPUnit\Framework\TestCase;

class PasarListaWarmTest extends TestCase
{
    /** @var FakeSCP */
    private $scp;

    protected function setUp(): void
    {
        $GLOBALS['__stic_pl_now'] = mktime(1, 30, 0, 11, 15, 2025);
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_options'] = array();
        // A propósito VACÍA: el calentador corre sin login, y si dependiera de
        // la sesión funcionaría en los tests y no en producción.
        $_SESSION = array();
        $this->scp = new FakeSCP();
        sticpa_pl_delegation_forced('');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__stic_pl_now']);
        sticpa_pl_delegation_forced('');
        $GLOBALS['__stic_filters'] = array();
    }

    // ---- La firma ---------------------------------------------------------

    public function test_sin_secreto_configurado_ninguna_firma_vale()
    {
        // Ni siquiera la firma "correcta" con secreto vacío: si el secreto no
        // está puesto, el endpoint no autoriza a nadie.
        $GLOBALS['__stic_filters']['sticpa_pl_warm_secret'] = '';
        $cuerpo = '{"ts":1,"delegaciones":["x"]}';
        $this->assertFalse(sticpa_pl_warm_signature_ok($cuerpo, hash_hmac('sha256', $cuerpo, '')));
        $this->assertFalse(sticpa_pl_warm_signature_ok($cuerpo, ''));
    }

    public function test_la_firma_buena_vale_con_y_sin_el_prefijo()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_warm_secret'] = 'secreto-de-prueba';
        $cuerpo = '{"ts":1,"delegaciones":["deleg-castellon"]}';
        $firma = hash_hmac('sha256', $cuerpo, 'secreto-de-prueba');

        $this->assertTrue(sticpa_pl_warm_signature_ok($cuerpo, $firma));
        $this->assertTrue(sticpa_pl_warm_signature_ok($cuerpo, 'sha256=' . $firma));
        // Mayúsculas: hash_hmac saca minúsculas, pero un cliente puede mandarlo
        // en mayúsculas y sería absurdo rechazarlo por eso.
        $this->assertTrue(sticpa_pl_warm_signature_ok($cuerpo, strtoupper($firma)));
    }

    public function test_un_cuerpo_tocado_invalida_la_firma()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_warm_secret'] = 'secreto-de-prueba';
        $cuerpo = '{"ts":1,"delegaciones":["deleg-castellon"]}';
        $firma = hash_hmac('sha256', $cuerpo, 'secreto-de-prueba');

        // Cambiar la delegación es JUSTO el ataque: calentar (y por tanto
        // invalidar) la caché de otra delegación.
        $otro = '{"ts":1,"delegaciones":["deleg-alicante"]}';
        $this->assertFalse(sticpa_pl_warm_signature_ok($otro, $firma));
    }

    public function test_otro_secreto_no_vale()
    {
        $cuerpo = '{"ts":1}';
        $GLOBALS['__stic_filters']['sticpa_pl_warm_secret'] = 'el-bueno';
        $this->assertFalse(
            sticpa_pl_warm_signature_ok($cuerpo, hash_hmac('sha256', $cuerpo, 'el-malo'))
        );
    }

    // ---- El sello de tiempo ----------------------------------------------

    public function test_una_peticion_vieja_no_vale()
    {
        $ahora = sticpa_pl_now();
        $this->assertTrue(sticpa_pl_warm_fresh($ahora));
        $this->assertTrue(sticpa_pl_warm_fresh($ahora - 60));
        // El reloj del runner puede ir adelantado: también se admite.
        $this->assertTrue(sticpa_pl_warm_fresh($ahora + 60));

        $this->assertFalse(sticpa_pl_warm_fresh($ahora - 3600));
        $this->assertFalse(sticpa_pl_warm_fresh($ahora + 3600));
        $this->assertFalse(sticpa_pl_warm_fresh(0));
        $this->assertFalse(sticpa_pl_warm_fresh(''));
    }

    // ---- La delegación a mano --------------------------------------------

    public function test_la_delegacion_a_mano_manda_sobre_la_sesion()
    {
        $_SESSION['scp_user_assigned_user_id'] = 'deleg-de-la-sesion';
        $this->assertSame('deleg-de-la-sesion', sticpa_pl_delegation($this->scp));

        sticpa_pl_delegation_forced('deleg-castellon');
        $this->assertSame('deleg-castellon', sticpa_pl_delegation($this->scp));

        // Y se puede quitar, que es lo que hace el calentador al terminar con
        // cada delegación: si no, la siguiente heredaría la anterior.
        sticpa_pl_delegation_forced('');
        $this->assertSame('deleg-de-la-sesion', sticpa_pl_delegation($this->scp));
    }

    // ---- Calentar de verdad ----------------------------------------------

    public function test_calentar_deja_la_cache_hecha()
    {
        $r = sticpa_pl_warm_delegation($this->scp, 'deleg-castellon');

        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame('deleg-castellon', $r['delegacion']);
        $this->assertGreaterThan(0, $r['grupos'], 'no ha cacheado ningún grupo');
        $this->assertGreaterThan(0, $r['eventos'], 'no ha cacheado ningún evento');
        $this->assertArrayHasKey('sesiones', $r);
        $this->assertArrayHasKey('inscripciones', $r);

        // LA COMPROBACIÓN QUE IMPORTA: después de calentar, pedir lo mismo no
        // vuelve a llamar al CRM. Sin esto el job saldría verde calentando nada.
        sticpa_pl_delegation_forced('deleg-castellon');
        $this->scp->calls = array();
        sticpa_pl_groups($this->scp);
        sticpa_pl_all_relationships($this->scp);
        sticpa_pl_etapa_events($this->scp);
        $this->assertSame(
            array(),
            $this->scp->calls,
            'la caché no quedó caliente: ' . implode(', ', $this->scp->calls)
        );
    }

    public function test_mientras_calienta_el_ttl_de_la_estructura_es_largo()
    {
        // La parte que más fácil se rompe en silencio. El TTL normal son 24
        // horas (antes 12, y calentado a la 1:30 caducaba a las 13:30 — ANTES
        // de las sesiones del sábado, que son por la tarde: el calentado había
        // sido para nada y nadie se enteraba). El del calentado sigue siendo
        // más largo aún, para que quepa el margen del día siguiente.
        $normal = sticpa_pl_ttl_structure();
        $this->assertSame(24 * HOUR_IN_SECONDS, $normal);
        $this->assertGreaterThan($normal, sticpa_pl_warm_ttl());

        $visto = null;
        // Se espía desde dentro: un filtro de más prioridad que el del
        // calentador ve el valor que este acaba de poner.
        $espia = function ($ttl) use (&$visto) {
            $visto = $ttl;
            return $ttl;
        };
        add_filter('sticpa_pl_ttl_structure', $espia, 100);
        sticpa_pl_warm_delegation($this->scp, 'deleg-castellon');
        remove_filter('sticpa_pl_ttl_structure', $espia, 100);

        $this->assertSame(26 * HOUR_IN_SECONDS, $visto, 'el TTL no se subió al calentar');
        // Y al terminar se queda como estaba: una página normal no tiene por qué
        // escribir cachés de 26 horas.
        $this->assertSame($normal, sticpa_pl_ttl_structure());
    }

    public function test_una_delegacion_vacia_no_calienta_nada()
    {
        $r = sticpa_pl_warm_delegation($this->scp, '');
        $this->assertArrayHasKey('error', $r);
        $this->assertSame(array(), $this->scp->calls);
    }

    public function test_calentar_no_deja_la_delegacion_puesta()
    {
        // Si se quedara puesta, la petición siguiente de ESE proceso de PHP
        // leería la caché de otra delegación. Es el fallo que más caro sale.
        sticpa_pl_warm_delegation($this->scp, 'deleg-castellon');
        $this->assertSame('', sticpa_pl_delegation_forced());
    }

    public function test_calentar_sube_la_generacion_antes_de_rellenar()
    {
        // El orden importa: si se rellenara antes de subir la generación, lo
        // recién pedido al CRM quedaría bajo la clave vieja y la subida lo
        // tiraría al momento. Se comprueba por el efecto: la generación sube, y
        // aun así la caché queda caliente (lo prueba el test de arriba).
        $option = sticpa_pl_cache_gen_option('struct', 'deleg-castellon');
        $antes = (int) get_option($option, 1);
        sticpa_pl_warm_delegation($this->scp, 'deleg-castellon');
        $this->assertGreaterThan($antes, (int) get_option($option, 1));
    }

    // ---- La familia de caché de los cargadores de colección --------------

    public function test_las_listas_y_las_asistencias_son_estado_no_estructura()
    {
        // Se llaman 'listas' y 'attrange', así que caían en 'struct' por el
        // defecto y `sticpa_pl_flush('state')` de después de guardar NO las
        // tiraba: la lista que acababas de pasar tardaba hasta cinco minutos en
        // aparecer.
        $this->assertSame('state', sticpa_pl_cache_family('listas'));
        $this->assertSame('state', sticpa_pl_cache_family('attrange'));
        $this->assertSame('state', sticpa_pl_cache_family('state'));
        $this->assertSame('state', sticpa_pl_cache_family('streaks'));
        // Y lo que sí es estructura sigue siéndolo.
        $this->assertSame('struct', sticpa_pl_cache_family('structure'));
        $this->assertSame('struct', sticpa_pl_cache_family('rels'));
        $this->assertSame('struct', sticpa_pl_cache_family('events'));
    }
}
