# Problema de entregabilidad del email (cae en SPAM)

> Estado: **pendiente** · Detectado 2026-06-14

## Síntoma

El correo del **enlace mágico** (acción "Envíame el enlace de acceso") llega a la carpeta
de **spam** de Gmail, con el aviso *"Este mensaje se parece a otros identificados como spam"*.

## Causa

El plugin envía con `wp_mail()` ([`inc/stic-action.php`](../inc/stic-action.php) →
`prefix_admin_stic_forgot_password`), que por defecto usa la **función `mail()` de PHP del
hosting** (`srv1049.main-hosting.eu`). Ese envío:

- No está **autenticado** (sin SPF/DKIM/DMARC alineados con el dominio del `From`).
- Sale de una IP de hosting compartido con mala reputación.
- El `From` (`admin_email`) no coincide con un dominio con registros de correo correctos.

Resultado: los filtros (Gmail) lo marcan como spam aunque el contenido sea legítimo.

## Lo que YA se ha arreglado (contenido del email)

- Enlace **absoluto** (antes era relativo `/area-privada/?...`, inservible).
- Email **HTML branded**, en **español**, con botón "Acceder a mi área privada" hiperenlazado
  + enlace de respaldo. `From` y `Reply-To` con nombre del portal.

Esto mejora la apariencia pero **no resuelve la deliverability** (es un problema de
autenticación/IP, no de contenido).

## Solución pendiente (elegir una)

### Opción A — Plugin SMTP (recomendada, sin código)
1. Instalar **WP Mail SMTP** (o FluentSMTP).
2. Configurar una cuenta de correo real del dominio (p. ej. `comunica@movimientoconsolacion.com`)
   o un proveedor (Brevo, Mailgun, SMTP del propio dominio).
3. Asegurar **SPF** y **DKIM** del dominio remitente en el DNS.

### Opción B — Resend (como el repo Comunica)
- El proyecto `mcmFormulariosComunica` ya envía con **Resend** (ver `crm_proxy.php`,
  acción `send_email`, remitente `comunica@movimientoconsolacion.com`).
- Se podría enganchar `wp_mail` a Resend (filtro `pre_wp_mail`) o llamar a su API REST.
- Requiere la **API key de Resend** y el dominio verificado en Resend (ya lo está para Comunica).

## Comprobación tras aplicar

- Enviar a una cuenta Gmail y revisar **Mostrar original** → `SPF=pass`, `DKIM=pass`, `DMARC=pass`.
- Probar en [mail-tester.com](https://www.mail-tester.com) (objetivo ≥ 8/10).
