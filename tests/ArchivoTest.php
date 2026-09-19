<?php

use PHPUnit\Framework\TestCase;

/**
 * LO ARCHIVADO SIGUE ARCHIVADO.
 * ----------------------------------------------------------------------------
 * El 19/09/2026 se retiraron del área tres secciones que no se usan —Relaciones
 * con la organización, Contactos de la organización y Organizaciones miembro—
 * y se guardaron en `pages/archivo/` por si algún día hacen falta.
 *
 * «Archivado» aquí quiere decir DOS cosas, y las dos se comprueban abajo:
 *
 *   1. que no se pueda llegar por URL, ni escribiendo la ruta a mano;
 *   2. que no quede ningún enlace vivo apuntando a ellas —que es justo como
 *      estaban antes: fuera del menú, pero alcanzables desde dos botones
 *      «Volver» de la ficha de perfil—.
 *
 * Si recuperas una, quítala de las listas de este archivo. Y si este test falla
 * sin que hayas recuperado nada, alguien ha dejado un enlace a una pantalla que
 * ya no está: el usuario vería una página en blanco.
 *
 * El detalle está en `pages/archivo/README.md`.
 */
class ArchivoTest extends TestCase
{
    /** Las pantallas que están archivadas ahora mismo. */
    private function archivadas()
    {
        return array(
            'list_stic_relationships',
            'single_stic_relationships',
            'list_stic_contacts',
            'single_stic_contacts',
            'list_stic_member_organizations',
        );
    }

    /** Los archivos del plugin donde un enlace vivo haría daño. */
    private function ficherosVivos()
    {
        $raiz = dirname(__DIR__);
        $ficheros = array_merge(
            glob($raiz . '/pages/*.php'),
            glob($raiz . '/inc/*.php'),
            array($raiz . '/menu.php', $raiz . '/sinergiacrm-private-area.php')
        );
        // Lo de `archivo/` no cuenta: ahí SÍ se nombran, y para eso está.
        return array_filter($ficheros, 'is_file');
    }

    public function test_las_pantallas_archivadas_estan_donde_deben(): void
    {
        $raiz = dirname(__DIR__);
        foreach ($this->archivadas() as $pantalla) {
            $this->assertFileExists(
                $raiz . '/pages/archivo/' . $pantalla . '.php',
                "{$pantalla} debería estar en pages/archivo/ (o haberse recuperado, y entonces toca actualizar este test)"
            );
            $this->assertFileDoesNotExist(
                $raiz . '/pages/' . $pantalla . '.php',
                "{$pantalla} ha vuelto a pages/ sin actualizar este test"
            );
        }
    }

    /**
     * NO SE LLEGA POR URL, Y NO HACE FALTA NINGUNA COMPROBACIÓN EXTRA.
     *
     * El enrutador solo admite `[a-z0-9_]+` y solo mira en `pages/`: el nombre
     * a secas ya no encuentra archivo, y la ruta con carpeta no pasa el filtro
     * porque lleva una barra. Esto fija esas dos propiedades, que son las que
     * hacen que archivar SEA deshabilitar.
     */
    public function test_el_enrutador_no_llega_a_lo_archivado(): void
    {
        if (!function_exists('sticpa_resolve_page_file')) {
            // La función vive en el archivo principal del plugin, que no se
            // puede cargar sin WordPress. Se replica su regla aquí: si allí
            // cambia, este test deja de valer y hay que mirarlo.
            $resolver = function ($page) {
                if ($page === '' || !preg_match('/^[a-z0-9_]+$/', $page)) {
                    return '';
                }
                $file = dirname(__DIR__) . '/pages/' . $page . '.php';
                return file_exists($file) ? $file : '';
            };
        } else {
            $resolver = 'sticpa_resolve_page_file';
        }

        foreach ($this->archivadas() as $pantalla) {
            $this->assertSame('', $resolver($pantalla), "?internalpage={$pantalla} no puede resolver");
            $this->assertSame('', $resolver('archivo/' . $pantalla), 'la ruta con carpeta tampoco');
        }
    }

    public function test_no_queda_ningun_enlace_vivo(): void
    {
        foreach ($this->ficherosVivos() as $fichero) {
            $contenido = file_get_contents($fichero);
            foreach ($this->archivadas() as $pantalla) {
                $this->assertStringNotContainsString(
                    "internalpage=" . $pantalla,
                    $contenido,
                    basename($fichero) . " enlaza a {$pantalla}, que está archivada: llevaría a una página en blanco"
                );
            }
        }
    }

    /**
     * Y SUS HANDLERS NO SE REGISTRAN.
     *
     * Era la mitad peligrosa: los dos estaban como `admin_post_nopriv_*` —sin
     * sesión—, volcaban todo el `$_REQUEST` en `set_entry()` y aceptaban
     * `stic-action=delete`, sobre `stic_Contacts_Relationships` (los grupos de
     * Pasar Lista) y sobre `Contacts` (las personas).
     */
    public function test_los_handlers_archivados_no_se_enchufan(): void
    {
        $acciones = file_get_contents(dirname(__DIR__) . '/inc/stic-action.php');
        foreach (array('single_stic_relationships', 'single_stic_contacts') as $accion) {
            $this->assertStringNotContainsString(
                "admin_post_nopriv_" . $accion,
                $acciones,
                "el handler de {$accion} sigue registrado: se archivó justamente para que no lo estuviera"
            );
        }
        // Y el código sigue guardado, que es la otra mitad del trato.
        $this->assertFileExists(dirname(__DIR__) . '/inc/archivo/stic-action-modulos-retirados.php');
    }
}
