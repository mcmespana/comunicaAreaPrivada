# Forma de pago de la persona

> Hecho el **05/11/2026**. Código: [`inc/stic-forma-pago.php`](../../inc/stic-forma-pago.php),
> pantalla [`pages/single_stic_forma_pago.php`](../../pages/single_stic_forma_pago.php), handler
> `prefix_admin_single_stic_forma_pago` en `inc/stic-action.php`, tests en `tests/FormaPagoTest.php`.
> Los campos y sus claves, en [`CAMPOS.md`](CAMPOS.md) → «Pago y domiciliación».

## Qué hace

Cualquier persona que entre al área ve cómo paga (domiciliación, transferencia o Bizum) y lo cambia, con su
IBAN y el titular. **Es de quien paga**: un menor no tiene forma de pago propia, así que un familiar que mira
a Lucía edita **la suya**, que es la que se usa para las cosas de Lucía. La persona sale de la sesión
(`sticpa_registration_payer()`), nunca de la petición.

Entradas, todas a la misma pantalla:

1. Menú «Tu perfil» → **Forma de pago** (para todos).
2. «Datos de Lucía»: una tarjeta que dice cuál es y lleva a cambiarla.
3. **Pagos**: botón «Cambiar forma de pago».
4. «Mis datos (familiar)»: un enlace (antes tenía unos campos falsos).

## Qué se toca al guardar

| Qué escribe la persona | Qué pasa |
|---|---|
| Domiciliación + un IBAN nuevo | Ficha (`ajmcm_forma_pago_c`, `ajmcm_iban_c`, `ajmcm_iban_titular_c`) **y** la cuenta de lo no cobrado (abajo) |
| Domiciliación sin escribir el IBAN | Solo la ficha (se queda el IBAN que había; no se pisan compromisos) |
| Transferencia o Bizum | Ficha (el IBAN que hubiera **no se borra**) + una nota en cada compromiso domiciliado afectado, para que tesorería lo revise. **No se cambia ninguna cuenta ni medio** |

### Lo que se actualiza y lo que no (decidido el 05/11/2026)

Solo lo que **no se ha pagado**. Un compromiso se actualiza únicamente si cumple **todo**:

- se cobra por domiciliación (`direct_debit`),
- sigue vigente (sin `end_date`, o con fin futuro),
- tiene algún pago **No remesado** (`not_remitted`),
- y **ningún otro pago suyo está en curso** (remesado, `unpaid`, a recobrar o un estado desconocido).
  `paid` y `cancelled` son historia y no cuentan.

| Compromiso | Resultado |
|---|---|
| Todos sus pagos hechos | No se toca |
| Un recibo ya en remesa o devuelto | No se toca (se cuenta y se avisa) |
| Otro medio (transferencia, tarjeta…) | No se toca |
| Terminado | No se toca |
| Domiciliado, vivo, con algo sin remesar | `bank_account` del compromiso + una línea en `description` (cuentas **tapadas**) + `bank_account` de cada pago `not_remitted` |

Es **todo o nada por compromiso**: si el compromiso no se actualiza, sus pagos tampoco. Si algo falla, la
pantalla lo dice («guardado, pero…») en lugar de mentir.

Más reglas, salidas de la revisión independiente del 05/11/2026:

- **Un recorrido a medias no vale.** `getRelatedElementsForLoggedUser` con `limit = 0` pagina y **nunca devuelve
  null**: si una página falla devuelve lo que llevaba y lo apunta en `SugarRestApiCall::lastWalkComplete()`. Se mira
  siempre (`sticpa_fp_walk_ok()`): con los compromisos a medias no se toca nada y la pantalla avisa; con los pagos de un
  compromiso a medias (podría faltar uno ya remesado) ese compromiso no se toca y también se avisa.
- **Solo se escribe lo que cambia.** Si el compromiso o un pago ya tienen esa cuenta, no se reescribe ni se deja una
  nota «de ES93 a ES93».
- **`active = 0` explícito** es un compromiso apagado a mano: no se toca.
- **IBAN de un país SEPA** para domiciliar (un IBAN de Brasil pasa el módulo 97, pero el banco rechaza el recibo).
- **Titular limpio**: sin etiquetas, sin entidades, sin espacios (ni duros) de más; se comprueba también tras guardar.
- **Hoy es hoy en Madrid** (`wp_date`), no en UTC: entre las 00:00 y las 02:00 un compromiso que acaba hoy se veía vivo.
- Si no se puede **leer** la ficha, no se pinta un formulario vacío que diga «todavía no has indicado cómo pagas».

> ⚠️ **Consecuencia de la regla del propietario, para que lo sepa tesorería:** un recibo **devuelto** (`unpaid`) —el caso
> típico de un IBAN mal puesto— NO se corrige solo, porque «en curso» incluye lo devuelto. Hoy hay 2 en el CRM. Los
> arregla tesorería a mano. Se actualizan los compromisos de **todas** las
delegaciones de esa persona: la cuenta es suya.

## Por qué no basta con guardar la ficha

Lo que se **cobra** es el pago, y cada pago lleva su **propia copia** de `bank_account`, heredada del
compromiso al crearse. No está verificado que el CRM propague un cambio del compromiso a pagos ya
generados (el manual dice que se adaptan «los futuros»), así que el área escribe también en ellos.

## Seguridad

- Sesión y firma del formulario (CSRF) como el resto de handlers; **sin código por correo** (decisión del
  propietario).
- El IBAN nunca se pinta entero (`ES93 •••• 8644`); el campo llega vacío y, si no se toca, se conserva.
- Tras guardar se **vuelve a leer** la ficha: el CRM descarta en silencio campos que no existen, y esto es
  dinero.
- Validación de IBAN por módulo 97 (`sticpa_iban_is_valid`), en el navegador y en el servidor.

## Datos de la migración (05/11/2026)

- 560 fichas con `cargo_cuenta` + IBAN: **385 son de menores**, el resto sin fecha de nacimiento (familiares).
  El IBAN histórico de un menor **no se lee ni se usa**: cuenta el del adulto que paga.
- De los 123 compromisos domiciliados con algún pago sin remesar, **121 los paga un adulto** y 2 un menor.
- Hay 2 domiciliaciones con pagos sin remesar y **sin cuenta**: conviene revisarlas antes de la próxima remesa.

## Pendiente

**Elegir cuál de dos familiares paga** (94 menores tienen dos familiares vigentes). Necesita guardar la
elección y no existe ningún campo: propuesta en [`CAMPOS.md`](CAMPOS.md) → «Quién paga por un menor».
Hoy paga el familiar que ha entrado, que es lo que ya hace la inscripción.

También pendiente: precargar la cuenta guardada al inscribirse (hoy se pide el IBAN en cada inscripción).
