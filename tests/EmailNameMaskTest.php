<?php

use PHPUnit\Framework\TestCase;

/**
 * `sticpa_mask_display_name` (`inc/stic-action.php`): el correo de acceso ya
 * no enseña el nombre completo del contacto, solo "Nombre + inicial del
 * apellido", por si el correo se reenvía o se abre en una pantalla ajena.
 */
class EmailNameMaskTest extends TestCase
{
    public function testNombreYApellidoSeReducenAInicial()
    {
        $this->assertSame('Juan P.', sticpa_mask_display_name('Juan Pérez García'));
        $this->assertSame('Juan P.', sticpa_mask_display_name('Juan Pérez'));
    }

    public function testUnaSolaPalabraSeQuedaTalCual()
    {
        // Típico de Cuentas (organizaciones): no hay apellido que enmascarar.
        $this->assertSame('Familia', sticpa_mask_display_name('Familia'));
    }

    public function testCadenaVaciaDevuelveVacio()
    {
        $this->assertSame('', sticpa_mask_display_name(''));
        $this->assertSame('', sticpa_mask_display_name('   '));
    }

    public function testEspaciosDeMasNoRompenElResultado()
    {
        $this->assertSame('Ana G.', sticpa_mask_display_name('  Ana   García  '));
    }

    public function testRespetaAcentosYEnies()
    {
        $this->assertSame('Íñigo Ñ.', sticpa_mask_display_name('Íñigo Ñúñez'));
    }
}
