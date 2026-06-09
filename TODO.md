# TODO / Roadmap — SinergiaCRM Private Area

Lista viva de tareas y proyectos del plugin. Pensada para que **agentes de IA o personas**
puedan coger una tarea, entender el porqué, y desarrollarla sin contexto previo.

> Antes de tocar nada, lee [`README.md`](README.md) (cómo funciona todo) y, para los proyectos
> grandes, los análisis en [`docs/`](docs/).

---

## 📖 Cómo usar esta lista (convenciones para agentes)

**Estado:** `[ ]` pendiente · `[~]` en progreso · `[x]` hecho · `[!]` bloqueado/decisión pendiente

**Prioridad:**
- 🔴 **P0** — crítico (seguridad o bloqueante). Hacer cuanto antes.
- 🟠 **P1** — alto valor, hacer pronto.
- 🟡 **P2** — medio, planificable.
- ⚪ **P3** — nice to have / futuro.

**Tamaño:** `S` (< medio día) · `M` (~1-3 días) · `L` (proyecto, semanas)

**Formato de cada tarea:**
```
- [ ] `ID` (Prioridad · Tamaño) Título — qué hay que hacer y criterio de "hecho".
      ↳ pistas: archivos/funciones implicadas.
```

**Reglas para agentes:**
1. Coge tareas de **prioridad más alta** primero, salvo que se te pida otra cosa.
2. Una tarea está "hecha" solo si cumple su criterio de aceptación; marca `[x]` y, si aplica,
   referencia el commit.
3. Si una tarea se vuelve grande, divídela en subtareas aquí antes de empezar.
4. **No rompas** el flujo de login actual ni la conexión al CRM sin avisar.
5. Las decisiones de arquitectura (los proyectos `L`) **se discuten antes** de implementar.

---

## 🔴 P0 — Seguridad y autenticación (lo primero)

> Contexto y diseño completo en
> [`docs/analisis-magic-links-tokens.md`](docs/analisis-magic-links-tokens.md).

- [ ] `AUTH-01` (P0 · M) **Login por token permanente** (`?token=`).
      Crear campo `ajmcm_pa_token_c` en el CRM (Studio, sin código), y un handler en `init` que
      detecte el token en la URL, busque el contacto y monte la sesión reutilizando la lógica de
      `PortalLogin`. Funciona aunque el contacto no tenga username/password. **Hecho =** un enlace
      con token válido loguea y redirige a URL limpia.
      ↳ `sinergiacrm-private-area.php` (login flow), `inc/stic-class-6.php::PortalLogin`.
- [ ] `AUTH-02` (P0 · S) **Limpiar el token de la URL tras login** (`wp_safe_redirect` a URL sin
      parámetros) para que no quede en historial/marcadores.
- [ ] `AUTH-03` (P0 · S) **Generar token por usuario** desde el admin (`set_entry` con
      `ajmcm_pa_token_c = bin2hex(random_bytes(16))`). **Hecho =** botón que genera/regenera el
      token de un contacto.
- [ ] `AUTH-04` (P0 · M) **Acceso mágico** (`?acceso_magico=`) firmado y caducable (HMAC +
      expiración corta, ~1h, sin campo en el CRM) para el flujo "introduce tu email y te mando
      acceso". Se envía al `email1` del contacto. Sustituye al forgot-password actual.
      ↳ reemplaza a `prefix_admin_stic_forgot_password` en `inc/stic-action.php`.
- [ ] `SEC-01` (P0 · S) **Dejar de enviar la contraseña en claro por email** en la recuperación
      (lo hace `prefix_admin_stic_forgot_password`). Sustituir por magic link (`AUTH-04`).
- [ ] `SEC-02` (P0 · M) **Escapar/parametrizar las queries al CRM.** Hoy se concatenan
      `username`/`password`/`token` sin escapar → inyección. Sanear en `PortalLogin`,
      `getUserExists`, `getUserInformationByUsername`, etc.
      ↳ `inc/stic-class-6.php`.
- [ ] `SEC-03` (P0 · M) **Hashear contraseñas** (si se mantiene el login por contraseña):
      `password_hash`/`password_verify`. Implica migrar el campo y el flujo de login/signup/cambio.
      Evaluar si, con `AUTH-*`, conviene **retirar** del todo el login por contraseña.
- [ ] `SEC-04` (P0 · S) **Activar verificación TLS** del CRM: `CURLOPT_SSL_VERIFYPEER => 1`
      (hoy está en `0` → vulnerable a man-in-the-middle).
      ↳ `inc/stic-class-6.php::call`.
- [ ] `SEC-05` (P1 · M) **Añadir nonces/CSRF** a todas las acciones `admin_post_*`
      (`wp_nonce_field` + `check_admin_referer`). Hoy los formularios no tienen protección CSRF.
      ↳ `inc/stic-action.php`, formularios en `pages/*` y `inc/stic-formController.php`.
- [ ] `SEC-06` (P1 · S) **Cookies de sesión seguras**: forzar `Secure`, `HttpOnly`, `SameSite=Lax`
      y exigir HTTPS en el área privada.

## 🟠 P1 — Panel de administración (gestión de accesos)

- [ ] `ADMIN-01` (P1 · M) **Buscador de usuarios** en el admin (por nombre/email vía
      `get_entry_list`), como base para ver/regenerar tokens e impersonar.
- [ ] `ADMIN-02` (P1 · S) **Ver / regenerar token** por usuario desde el buscador.
- [ ] `ADMIN-03` (P1 · M) **Regenerar tokens masivamente** (todos o un subconjunto), con aviso de
      que invalida los enlaces de emails ya enviados.
- [ ] `ADMIN-04` (P1 · M) **"Entrar como" (impersonación)** desde el admin: solo `manage_options`,
      con **audit log**, **banner visible** de impersonación y, preferiblemente, mediante enlace de
      un solo uso y corta vida (no exponiendo el token permanente en la tabla).
- [ ] `ADMIN-05` (P2 · S) Campo **URL de portal precalculada** (`ajmcm_pa_portal_url_c`) para
      arrastrar como mail-merge en las plantillas de email del CRM.

## 🟡 P2 — Plataforma / Expo (decisión de fondo)

> Contexto y estrategia en
> [`docs/analisis-expo-migracion.md`](docs/analisis-expo-migracion.md). **Decidir antes de
> implementar.**

- [!] `PLAT-00` (P2 · —) **Decisión:** Opción 1 (mantener PHP web + app nativa aparte) vs.
      Opción 2/3 (monorepo Expo con `core` compartido + web "lite"). Requiere medir el peso del
      bundle web con un MVP antes de comprometerse.
- [ ] `PLAT-01` (P2 · M) **Convertir el plugin en BFF**: exponer endpoints REST
      (`register_rest_route`) reutilizando el cliente PHP (`/login-token`, `/me`,
      `/mis-inscripciones`…). Permite probar Expo contra datos reales sin reescribir backend.
- [ ] `PLAT-02` (P2 · L) **Paquete `core` en TypeScript**: portar `inc/stic-class-6.php` (cliente
      CRM + tipos + reglas tutor/menor) para compartir entre app nativa y web.
- [ ] `PLAT-03` (P2 · L) **MVP Expo** (login por token + eventos + inscripciones + documentos) y
      **medir el bundle web** para alimentar la decisión `PLAT-00`.
- [ ] `PLAT-04` (P2 · M) **Motor de formularios declarativo en React** (port de `makeForm`).

## ⚪ P2/P3 — Frontend / estilos

- [x] `UI-01` (P1 · M) Capa de estilos premium en `css/custom-style.css` (glassmorphism, modo
      oscuro, gradientes, micro-interacciones) + reordenar `enqueue` para que cargue la última.
      ↳ hecho.
- [ ] `UI-02` (P2 · S) Ajustar la **paleta** a la marca real (hoy índigo/cian de ejemplo): editar
      `--primary-color` / `--secondary-color` en `css/custom-style.css`.
- [ ] `UI-03` (P2 · M) **Verificar el diseño en un WordPress real** (staging) y pulir responsive en
      las pantallas de listado/detalle de cada módulo.
- [ ] `UI-04` (P3 · S) Limpiar los CSS `*.backup` y consolidar `stic-style` / `stic-modern-style`
      si dejan de ser necesarios.

## ⚪ P2/P3 — Mantenimiento y calidad

- [ ] `MNT-01` (P2 · S) Quitar/condicionar las funciones de **debug** (`debug()`, `my_log_file()`)
      para que no escriban logs ni pinten en producción.
      ↳ `sinergiacrm-private-area.php`.
- [ ] `MNT-02` (P2 · S) Revisar `getDestinationModule()` y el uso de `$_REQUEST` directo (evitar
      *warnings* de índices indefinidos y posibles manipulaciones).
- [ ] `MNT-03` (P3 · M) Tests/healthcheck básico de la conexión al CRM y de los flujos críticos
      (login por token, signup, subida de documento).
- [ ] `MNT-04` (P3 · S) Internacionalización: revisar que todas las cadenas nuevas pasen por
      `__()` y actualizar los `.po/.pot`.

## ⚪ Documentación

- [x] `DOC-01` (P1 · M) README técnico y funcional. ↳ hecho.
- [x] `DOC-02` (P1 · M) Análisis Expo y Magic Links en `docs/`. ↳ hecho.
- [ ] `DOC-03` (P3 · S) Documentar los endpoints REST del BFF cuando existan (`PLAT-01`).

---

## 🧭 Orden sugerido de ataque

1. **`AUTH-01` → `AUTH-03`** (login por token usable ya en emails) + **`SEC-04`** (TLS).
2. **`AUTH-04` + `SEC-01`/`SEC-02`** (magic links seguros, fin del password en claro).
3. **`ADMIN-01` → `ADMIN-04`** (gestión e impersonación desde el admin).
4. **`SEC-03`/`SEC-05`/`SEC-06`** (endurecer lo que quede).
5. **`PLAT-00`** decisión, luego `PLAT-01` → `PLAT-03` si se va a Expo.

> Mantén esta tabla actualizada: al terminar una tarea, márcala `[x]` y, si surge trabajo nuevo,
> añádelo con su `ID`, prioridad y tamaño.
