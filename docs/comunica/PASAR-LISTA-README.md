# Pasar Lista — índice

Sustituye el AppSheet de asistencia por pantallas dentro del área privada de
Comunica. Se usa desde el navegador y desde **MCM App**, que es una webview: lo
que se rompa en móvil se rompe en la app.

> **¿Vienes nuevo o empiezas una conversación limpia?** Empieza por
> [`PASAR-LISTA-ESTADO.md`](PASAR-LISTA-ESTADO.md): qué funciona, qué está roto
> y por dónde seguir. Este índice es el mapa; ese documento es el parte.
>
> Hay también una **versión web del parte**, para consultarla o pasarla a
> alguien sin darle acceso al repo:
> <https://claude.ai/code/artifact/0701d2cf-bf96-4b5d-b3c9-b5db712476a7>

---

## Los documentos, y cuál abrir

| Si buscas… | Documento |
|---|---|
| **Estado, bugs abiertos y cómo probar** | [`PASAR-LISTA-ESTADO.md`](PASAR-LISTA-ESTADO.md) |
| Qué tiene que pasar y cómo son las pantallas | [`PASAR-LISTA.md`](PASAR-LISTA.md) |
| Nombres exactos de campos y módulos del CRM | [`PASAR-LISTA-CAMPOS-CRM.md`](PASAR-LISTA-CAMPOS-CRM.md) |
| Fases y melones pendientes | [`PASAR-LISTA-ROADMAP.md`](PASAR-LISTA-ROADMAP.md) |
| Coordinación, monitores y quién ve qué | [`PASAR-LISTA-COORDINACION.md`](PASAR-LISTA-COORDINACION.md) |
| Los recuentos que lleva el grupo en su ficha | [`PASAR-LISTA-RECUENTOS.md`](PASAR-LISTA-RECUENTOS.md) |
| Seguimientos de monitores (`stic_FollowUps`) | [`PASAR-LISTA-SEGUIMIENTOS.md`](PASAR-LISTA-SEGUIMIENTOS.md) |
| El trabajo de madrugada y el calentado de caché | [`GUARDIAN-NOCTURNO.md`](GUARDIAN-NOCTURNO.md) |
| ¿BBDD espejo? — análisis y decisión (27/08/2026) | [`DECISION-BBDD-ESPEJO.md`](DECISION-BBDD-ESPEJO.md) |
| **Planes de trabajo ejecutables** (bug del guardado, rendimiento, fidelidad, funcionalidad, UX, seguimiento de monitores) | [`../../plans/`](../../plans/) — 033 a 038 |
| Colores, tipografía, componentes | [`../design-system.md`](../design-system.md) |
| El contrato con la app (webview) | [`CONTRATO-APP-WEBVIEW.md`](CONTRATO-APP-WEBVIEW.md) |

**Y la regla que manda sobre todas:** los nombres de campo del CRM salen de
[`CAMPOS.md`](CAMPOS.md), que es la fuente de la verdad. No se inventan ni se
suponen. Si `CAMPOS.md` cambia, el cambio sube también al repo
`comunicaFormularios`, que escribe en esos mismos campos.

---

## El diseño

Los mockups viven **en el repo**, no en una herramienta externa:

```
design/pasar-lista/
├── Main.dc.html       1 · Home (atajo a la sesión de hoy + listas pendientes)
├── Grupos.dc.html     2 · Etapas y grupos
├── Marcar.dc.html     3 · Marcar asistencia (interactivo)
├── Estados.dc.html    4 · Estados de la hoja de asistencia
├── Ficha.dc.html      5 · Ficha del participante y contacto con la familia
├── Resumen.dc.html    6 · Resumen de grupos para coordinación
├── canvas.json        colocación de los artboards y notas
└── pasar-lista-mcm.html   el canvas ya montado (GENERADO, no se edita a mano)
```

Cada `.dc.html` es una pantalla de 390 px con **todo su CSS en línea**. Eso
significa que los colores, los radios, los tamaños y los espaciados **están
ahí, medidos**. No basta con leer el texto de los artboards: hay que leer su
CSS. Es exactamente el error que produjo la ronda de bugs visuales de agosto de
2026 — ver [`PASAR-LISTA-ESTADO.md`](PASAR-LISTA-ESTADO.md) §«Las trampas».

Los valores del diseño coinciden 1:1 con los tokens de `css/custom-style.css`
§1: `#e6fcf5` es `--success-50`, `#fffbeb` es `--warning-soft`, y así todos. Si
un artboard usa un hex, hay un token con ese valor: **usa el token**.

---

## El código

| Archivo | Qué hay dentro |
|---|---|
| `inc/stic-pasar-lista.php` | Lógica **pura**, sin CRM: qué sesión se ofrece, porcentajes, ausencias seguidas, orden de los grupos, nombre corto. Es lo más fácil de testear y lo primero que hay que mirar. |
| `inc/stic-pasar-lista-crm.php` | **Todas** las consultas y escrituras al CRM, y la caché. El archivo grande. |
| `inc/stic-pasar-lista-ui.php` | Los trozos de HTML compartidos y los iconos. |
| `inc/stic-pasar-lista-warm.php` | El endpoint que calienta la caché (lo llama el Guardián). |
| `inc/stic-pasar-lista-sw.php` | Service worker del modo sin conexión (apagado por defecto). |
| `pages/single_stic_pasar_lista*.php` | Una pantalla por archivo. Escriben en `$html`. |
| `js/stic-pasar-lista.js` | Marcado, gestos, hoja de estados, borrador y cola offline, buscador. |
| `css/pasar-lista.css` | Solo Pasar Lista. Se apoya en los tokens de `custom-style.css` §1. |

Las pantallas, y a qué artboard corresponde cada una:

| URL (`?internalpage=…`) | Artboard |
|---|---|
| `single_stic_pasar_lista` | `Main` |
| `single_stic_pasar_lista_grupos` | `Grupos` |
| `single_stic_pasar_lista_marcar` | `Marcar` + `Estados` |
| `single_stic_pasar_lista_ficha` | `Ficha` |
| `single_stic_pasar_lista_resumen` | `Resumen` |
| `single_stic_pasar_lista_monitores`, `_monitor`, `_reuniones` | sin artboard todavía — el diseño de `_monitor` está escrito en [`PASAR-LISTA-COORDINACION.md`](PASAR-LISTA-COORDINACION.md) §4 y en [`../../plans/038-seguimiento-de-monitores.md`](../../plans/038-seguimiento-de-monitores.md) |

---

## Los tests

```bash
composer install
vendor/bin/phpunit                  # todo
vendor/bin/phpunit --filter PasarLista
node --test .github/scripts/guardian/guardian.test.mjs   # el Guardián
```

| Suite | Para qué |
|---|---|
| `PasarListaTest` | La lógica pura. |
| `PasarListaRenderTest` | Ejecuta las páginas **de verdad** contra un CRM falso (`FakeSCP`). Es lo que detecta una pantalla que revienta. |
| `TransportLinkListTest` | El ensamblado de la respuesta de la API. Aquí vive la lección de los enlaces anidados. |
| `CosteLlamadasTest` | Cuántas llamadas al CRM hace cada pantalla, **con topes**. |
| `TokensCssTest` | Que ningún `var(--token)` de la hoja esté sin definir. |
| `PasarListaWarmTest` | La firma, el replay y que el calentado deje la caché hecha. |

**Aviso sobre los dobles de test:** hubo 175 tests en verde con la producción
rota porque el doble devolvía una forma de API que la API real no devuelve. Si
escribes un doble nuevo, que construya la forma **real** y la pase por el
ensamblado del propio plugin, no por uno inventado. Está explicado en
`PASAR-LISTA-ESTADO.md`.

---

## Desplegar

`main` → merge a `production` → el workflow `deploy-produccion.yml` sube por
FTPS. Detalles en [`../despliegue.md`](../despliegue.md).

Entorno de pruebas: `https://comunica.movimientoconsolacion.com/aptest/`.
