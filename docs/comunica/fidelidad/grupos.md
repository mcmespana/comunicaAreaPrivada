# Fidelidad — `Grupos` (árbol de etapas y grupos)

Artboard: [`design/pasar-lista/Grupos.dc.html`](../../../design/pasar-lista/Grupos.dc.html)
· Pantalla: `pages/single_stic_pasar_lista_grupos.php` · CSS: `css/pasar-lista.css`

**La spec es el `style="…"` en línea del artboard, no su captura.** Esta tabla la
extrae para que nadie tenga que volver a medirla, y para que el próximo cambio
se compare contra algo. Los colores van traducidos a tokens de
`css/custom-style.css` §1: si el artboard usa un hex, hay un token con ese valor.

Revisada el **28/08/2026**.

## La spec, componente a componente

| Componente | Propiedad | Spec |
|---|---|---|
| Fondo | | `#f7f9fc` → `--surface-2` |
| Cabecera | relleno · hueco | `22px 20px 14px` · `12px` |
| ↳ flecha atrás | | 22 px, `--gray-700`, trazo 2.4 |
| ↳ punto de etapa | | 10 px redondo, color de la etapa |
| ↳ título | | 1.3 rem · 800 · `-0.02em` |
| ↳ pastilla «5 grupos» | | `.16rem .6rem` · redonda · `--gray-100` sobre `--gray-600` · 0.72 rem · 800 |
| Buscador | | alto 48 px · `0 16px` · radio `--radius-lg` · `--surface` · borde 1 px `--gray-200` |
| ↳ icono · texto | | 18 px `--gray-400` · 0.92 rem `--gray-400` |
| Leyenda | | `0 20px 12px` · hueco 16 px · item 6 px |
| ↳ etiqueta | | 0.72 rem · 600 · `--gray-500` |
| ↳ glifos | | check 14 px `--success-color`; círculo hueco 12 px borde 2 px `--gray-300`; «no hubo» 14 px `--gray-400` |
| Bloques | hueco entre tarjetas | 10 px |
| **Tu grupo** | filete | radio 1.15 rem · 1.5 px de `--grad-brand` · sombra `0 12px 30px` del azul al 16 % |
| ↳ interior | | radio 1.02 rem · `14px 15px` · hueco 13 px |
| ↳ avatar | | 42 px · `--grad-brand` · 0.86 rem · 800 |
| ↳ estado | | disco 26 px relleno, check 14 px blanco trazo 3.4 |
| Tarjeta de lista | | radio `--radius-lg` · `--surface` · `--shadow-xs` |
| ↳ fila | | `15px 16px` · hueco 14 px |
| ↳ separador | | 1 px `--gray-100` |
| ↳ código | | 1.05 rem · 800 · `-0.01em` |
| ↳ nombre | | 0.9 rem · 600 · `--gray-600` |
| ↳ hueco código–nombre | | 8 px |
| ↳ línea de datos | | 0.82 rem · `--gray-500` · hueco con la de arriba 3 px |
| ↳ **estado** | | **glifo suelto de 20 px, sin disco**: check `--success-color` trazo 3 · círculo hueco borde 2 px `--gray-300` · «no hubo» `--gray-400` trazo 2.4 |
| ↳ chevron | | 17 px · `--gray-400` · trazo 2.4 |
| Sin grupo | | radio `--radius-lg` · `--warning-soft` · borde 1 px discontinuo ámbar claro · `14px 16px` · hueco 12 px |
| ↳ icono · título · sub | | 18 px `--warning-text` · 0.9 rem 700 `--warning-darker` · 0.76 rem `--warning-text` |

## Desvíos corregidos el 28/08/2026

| Qué | Spec | Estaba |
|---|---|---|
| Estado de la fila | glifo suelto de 20 px | disco relleno de 26 px |
| Estado en «tu grupo» | disco de 26 px | igual (correcto) |
| Relleno de fila | `15px 16px` | `0.9rem 1rem` (14.4 px) |
| Hueco de la fila · del cuerpo | 14 px · 3 px | 13.6 px · 2.4 px |
| Código del grupo | 1.05 rem · `-0.01em` | 1.15 rem · `-0.02em` (era el tamaño del TÍTULO de la pantalla) |
| Nombre del grupo | 0.9 rem | 0.92 rem |
| Título de la sección | 1.3 rem | 1.15 rem |
| Punto de la cabecera | 10 px | 8 px |
| Pastilla del recuento | `--gray-100`/`--gray-600` · 0.72 rem · 800 | `--surface-2`/`--gray-500` · 0.78 rem · 600 |
| Buscador | alto 48 px · radio `--radius-lg` | alto 44 px · radio redondo |
| Icono del buscador | 18 px `--gray-400` | 17 px `--gray-500` |
| Leyenda | 0.72 rem `--gray-500`, glifos 14/12 px | 0.74 rem `--gray-600`, glifos en disco de 16 px |
| Interior de «tu grupo» | `14px 15px` · hueco 13 px · radio 1.02 rem | `0.85rem 0.95rem` · 12.8 px · 1.1 rem |
| Chevron | `--gray-400` | `--gray-300` |
| Tarjeta «sin grupo» | borde 1 px ámbar claro · `14px 16px` · icono 18 px · título 0.9 rem 700 · sub 0.76 rem `--warning-text` | borde 1.5 px `--warning-color` · `0.9rem 1rem` · icono 22 px · título 0.95 rem 650 · sub 0.85 rem `--gray-500` |

## Divergencias DELIBERADAS (no las «arregles» de vuelta)

- **El texto del buscador se queda en 1 rem**, no en los 0.92 rem del artboard.
  Por debajo de 16 px reales, iOS hace zoom al enfocar un campo y descoloca la
  pantalla entera dentro de la webview. El artboard es un dibujo; esto es un
  campo de verdad. Lo que sí baja a 0.92 rem es el *placeholder*.
- **El buscador es pegajoso (`sticky`) y lleva sombra.** No está en el artboard,
  que dibuja cinco grupos; con veintiocho, perderlo al bajar es peor.
- **El borde de «sin grupo» usa `--warning-200`**, no el `#fcd34d` exacto del
  artboard: ese hex no existe como token y el sistema de diseño manda que no se
  inventen tokens por cuenta propia (plan 035, STOP conditions). La diferencia
  es de un punto de luminosidad en un borde discontinuo.
- **El chevron vive en una caja de 40×44 px** aunque el icono mida 17: es un
  objetivo táctil, y la fidelidad no manda sobre la accesibilidad. Es la misma
  divergencia ya escrita para los botones de llamar y WhatsApp de la `Ficha`.
