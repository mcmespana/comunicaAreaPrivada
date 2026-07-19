# Plan 015 (spike): Conectar o bloquear el formulario de pago del familiar

> **Executor instructions**: este es un plan de INVESTIGACIÓN/DECISIÓN (spike). Sigue los pasos,
> documenta hallazgos, y NO conviertas los campos provisionales en persistentes hasta confirmar el
> destino real en el CRM. Respeta STOP conditions. Actualiza `plans/README.md` al terminar.
>
> **Drift check**: `git diff --stat bc3c436..HEAD -- pages/single_stic_tutor_profile.php inc/stic-action.php`

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: plans/013-verification-baseline.md (toca datos de pago)
- **Category**: direction
- **Planned at**: commit `bc3c436`, 2026-07-19
- **Nota**: relacionado con `FAM-01`/`FAM-02` de `TODO.md`.

## Why this matters

La pantalla de datos del familiar muestra un formulario de domiciliación (método de pago, IBAN,
titular) con validación de IBAN incluida, pero los campos usan nombres provisionales
(`ajmcm_pago_*_c`) que el CRM **no tiene**, así que `set_entry` los ignora silenciosamente: el
usuario introduce sus datos bancarios y **se descartan**. Es la mayor brecha entre UI publicada y
backend, y un problema de confianza/integridad justo en el caso de uso "un familiar gestiona a
varios participantes" que UI-14 estrenó. Hay que cerrar la brecha: o se persisten de verdad, o se
deja claro que aún no y no se invita a rellenarlos como si funcionaran.

## Current state

- `pages/single_stic_tutor_profile.php:9-15` (cabecera) documenta el "front adelantado":
  > "En SinergiaCRM/Comunica aún NO está definido dónde viven estos datos. Los campos usan nombres
  > provisionales (ajmcm_pago_*_c). El guardado es inocuo: la API set_entry ignora los campos que no
  > existen en el CRM."
- Campos provisionales (buscar en el archivo): `ajmcm_pago_metodo_c`, `ajmcm_pago_iban_c`,
  `ajmcm_pago_titular_c`; hay un aviso ⚙️ en la UI ("Esta sección se está conectando… es posible que
  los cambios aún no queden guardados").
- Guardado: `prefix_admin_single_stic_tutor_profile` (`inc/stic-action.php:90+`), `set_entry` con
  `id = scp_tutor_user_id`.
- Contexto de diseño: `docs/design-system.md` §6 ("Medio de pago (front adelantado)") y §"Perfiles
  de familia". `FAM-01`/`FAM-02` en `TODO.md:166-171` esperan las relaciones
  `stic_Personal_Environment` y el mapeo a `stic_Payment_Commitments`.

## Commands you will need

| Propósito | Comando | Esperado |
|-----------|---------|----------|
| Lint | `php -l pages/single_stic_tutor_profile.php && php -l inc/stic-action.php` | sin errores |

## Scope

**In scope**: `pages/single_stic_tutor_profile.php`, `inc/stic-action.php` (solo el handler del
tutor), y documentar la decisión.
**Out of scope**: crear campos en el CRM (eso es trabajo de Studio, fuera del repo); tocar el resto
de la pantalla del familiar.

## Steps

### Step 1 (investigación): determinar el destino real de los datos de pago

Consulta `docs/comunica/CAMPOS.md` y con el equipo de Comunica/CRM: ¿existen ya campos de método de
pago/IBAN/titular en Contacts/Accounts, o deben ir a `stic_Payment_Commitments`? Documenta la
respuesta como comentario en la cabecera del archivo y en `TODO.md` (FAM-02). Esto decide la rama:
- **Rama A — existe destino**: hay campos/módulo reales donde persistir.
- **Rama B — no existe aún**: no hay dónde guardar de forma fiable.

**Verify**: la decisión (A o B) queda escrita en el archivo y en `TODO.md`.

### Step 2A (si Rama A): mapear los campos reales y persistir

Renombra los `'name'` `ajmcm_pago_*_c` por los nombres reales del CRM (o adapta el handler para
escribir en `stic_Payment_Commitments` vía la relación correcta). Escribe un test (plan 013) que
confirme que un guardado llega al campo/módulo real (con el CRM mockeado). Quita el aviso ⚙️ "puede
que no se guarde".

**Verify**: `composer test` (plan 013) cubre el guardado del método de pago y pasa.

### Step 2B (si Rama B): no invitar a introducir datos que se descartan

Hasta que exista destino, evita el problema de confianza: deja los campos **deshabilitados**
(`disabled`) con un texto claro ("Disponible próximamente") en lugar de inputs activos que aceptan y
tiran los datos, o retira la sección de la pantalla tras el aviso. Mantén el código listo (comentado
o tras un flag) para reactivarlo cuando llegue el destino real.

**Verify**: en la pantalla del familiar, los campos de pago no aceptan entrada que luego se pierda
(están deshabilitados o no se muestran); `php -l` sin errores.

## Test plan

- Rama A: test de guardado (plan 013) verde; guardar IBAN/titular persiste en el CRM (staging).
- Rama B: verificación manual de que no se puede introducir un IBAN que se descarte en silencio.

## Done criteria

- [ ] Decisión (A/B) documentada en el archivo y en `TODO.md` (FAM-02)
- [ ] Rama A: campos mapeados a destino real + test verde + aviso ⚙️ retirado; **o**
      Rama B: campos deshabilitados/ocultos con mensaje claro
- [ ] `php -l` de los archivos tocados exit 0
- [ ] Fila 015 actualizada en `plans/README.md`

## STOP conditions

- No se puede confirmar el destino real de los datos de pago con el equipo/CRM → ejecuta la Rama B
  (deshabilitar) y reporta; NO dejes inputs activos que descartan datos bancarios.
- Persistir el pago toca el modelo de compromisos de pago de forma no trivial → para y reporta; puede
  ser un plan propio mayor.

## Maintenance notes

- Este es el punto donde FAM-01 (relaciones familiares) y FAM-02 (medio de pago) convergen; cuando
  FAM-01 conecte las relaciones reales, revisar que el `id` sobre el que se guarda sigue siendo el
  del familiar.
- Reviewer: por encima de todo, que no exista una ruta donde un usuario meta datos bancarios que se
  pierdan sin avisar.
