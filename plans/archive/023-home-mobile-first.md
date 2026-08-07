# 023 — Home móvil: accesos compactos y orden con sentido

**Estado:** DONE (verificado en render offline con Chromium a 330/390/1280 px, claro y oscuro).
**Prioridad:** P2 · **Esfuerzo:** S-M · **Depende de:** —

## Problema

El área privada se usa sobre todo desde el móvil, y la home era la pantalla que
peor lo llevaba:

1. **Una sola columna de tarjetas muy altas.** Cada acceso pintaba icono de
   54 px + título + descripción + un "Entrar" que era redundante (la tarjeta
   entera ya es el enlace). Resultado: ~1,5 accesos por pantalla y scroll para
   descubrir que hay más secciones.
2. **Sin jerarquía.** "Cambiar contraseña" pesaba visualmente lo mismo que
   "Eventos", aunque se toque una vez cada muchos meses.
3. **Hover pegado.** Los efectos de `:hover` (elevación, giro del icono) los
   simula el navegador al tocar y se quedaban aplicados hasta tocar en otro
   sitio.

## Qué se ha hecho

### `pages/single_stic_home.php`
- Las tarjetas se reparten en **dos grupos**: "Tu día a día" (eventos,
  inscripciones, calendario, documentos, pagos…) y **"Tu cuenta"** (mis datos,
  monitor/a, cambiar contraseña…), este último fuera de la rejilla principal,
  al cierre de la página.
- Orden explícito por prioridad (`$mainPriority`); las claves que no estén en
  la lista conservan el orden del menú, así que **añadir una sección nueva
  sigue funcionando sin tocar nada**.
- El pintado de la tarjeta se extrae a un closure `$renderCard` (mismo HTML que
  antes) para no duplicarlo entre los dos grupos.

### `css/custom-style.css` — nueva §48
- **Móvil (≤640px):** rejilla de **2 columnas compactas** tipo "iconos de app"
  (icono 38 px + nombre, título a 2 líneas como máximo). Se ocultan la
  descripción y el "Entrar". Hero y agenda también más compactos. Pasan de
  ~1,5 accesos a **6 visibles sin scroll**.
- **≤340px:** una columna, pero con la tarjeta en horizontal.
- **Escritorio (≥641px):** algo más de densidad (columnas de 210 px, icono
  46 px) sin perder la descripción.
- **Grupo "Tu cuenta"**: `.stic-dashboard-grid--mini`, filas bajas en todos los
  tamaños.
- **Táctil:** los efectos de hover solo bajo `@media (hover: hover)`; en táctil,
  feedback de pulsación (`:active`) y `-webkit-tap-highlight-color: transparent`.

## Notas para quien venga después
- La regla de `@media (max-width: 560px)` de §16 ya no toca la rejilla del
  dashboard: manda §48.
- Ocultar `.stic-dash-desc` con `display:none` también la quita del lector de
  pantalla; el nombre accesible del enlace sigue siendo el título de la
  sección, que es lo que describe el destino.
