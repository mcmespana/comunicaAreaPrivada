# 044 — Firmas dentro de «Documentos» (web y app)

> **Anotado el 1/11/2026 por el propietario. Sin diseñar ni decidido.** Es la idea tal cual; lo de «Preguntas» hay que contestarlo antes de empezar.

## La idea

Que en el área privada —**la web (este plugin) y la MCM App**— aparezcan las **firmas**: los documentos **por firmar** y los **firmados**. El sitio natural es **Documentos**:

- **Subidos** (lo que ya hay) y **Por firmar** (lo que el CRM ha mandado a firmar y aún no está firmado), como dos cosas distintas arriba.
- Los **firmados** pasan a ser **un documento más**: entran en la lista de documentos, **ordenados por fecha y por categoría**, sin tratamiento aparte.

## Qué hay ya (para no partir de cero)

- El CRM tiene el módulo **Firmas** (`stic_Signatures` y `stic_Signers`: proceso, firmantes, estado, enlace de firma). Comunica Tools ya lo lee para el seguimiento de coordinación: `comunicaTools:docs/FIRMAS.md` (estados del firmante, `pending` ↔ `unnecessary`, enlace de firma).
- La app tiene «Documentos» de familias (planes 16 y siguientes de `mcmapp:docs/planes/area-nativa/`) y el middleware, sus rutas `/v1/profiles/:pid/documents` (`comunicaMiddleware:src/app/profile-docs.ts`).

## Preguntas abiertas

1. **¿De dónde sale «por firmar» para una persona?** ¿Del firmante con estado pendiente cuyo contacto es esa persona? ¿Y para un hijo, firma el tutor (de quién es el enlace)?
2. **¿Dónde queda el documento firmado?** ¿El PDF firmado se guarda como un `Documents` del contacto (entonces ya sale en la lista) o solo vive en el módulo Firmas?
3. **Categoría y fecha** de un firmado: ¿la del proceso de firma, la de la firma o la del documento?
4. **Firmar desde la app**: ¿abre el enlace de firma en el navegador del móvil o hay algo nativo?
5. **Salud de la privacidad**: lo de un hijo solo a quien tiene la relación vigente (`allowedProfile`), como el resto de familias.
6. Middleware: una ruta nueva (`/v1/profiles/:pid/signatures`) o un campo `kind` (`uploaded` / `to_sign` / `signed`) en `/documents`. La segunda deja la app más simple.

## Dónde se hace

- Web: este repo (`pages/…documents…`, ver `docs/comunica/`).
- App: `mcmapp` (pantalla de Documentos) y `comunicaMiddleware` (lectura). Anotado también en `mcmapp:docs/planes/area-nativa/PENDIENTE-REVISAR.md`.
