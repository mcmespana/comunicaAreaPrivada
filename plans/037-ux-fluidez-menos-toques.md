# 037 — UX: fluidez, menos toques, y la ficha a un gesto

**Prioridad: P1-P2 por fila.** Esfuerzo: S-M por fila. Depende de: 033 (varias
filas tocan el flujo de guardado); combina bien con 035 (mismo código de UI).

La vara de medir, dictada por el propietario: **todo muy fluido, fácil,
rápido, los mínimos toques posibles**, y que desde pasar lista se llegue sin
esfuerzo a la ficha del chaval (o del monitor) con toda la información
interesante. Público: monitores con el móvil en la mano un sábado por la
tarde, dentro de la webview de MCM App.

> ## Estado al 27/08/2026 (noche)
>
> **Hecho:**
>
> - **Fila 1 (el guardado no miente ni pierde marcas):** el borrador solo se
>   tira cuando el servidor confirma, la cola sin conexión solo saca una entrada
>   con confirmación real, y «Lista guardada» está contrastado contra el CRM.
>   Ver plan 033.
> - **Fila 1 (siguiente acción):** tras un guardado CONFIRMADO, la pantalla
>   ofrece «Ver el resumen» y «Otro grupo». Antes se quedaba igual que estaba:
>   el monitor había terminado y la aplicación no le decía nada.
> - **Fila 3 (la ficha sirve):** la familia y sus teléfonos ya salen —no salían
>   en ninguna ficha— con el parentesco traducido y en su sitio.
> - **Fila 5 (menos toques):** desde la hoja de estados se llega a la ficha. Al
>   marcar una falta, lo siguiente que se quiere es el teléfono de casa, y la
>   hoja tapa la flecha de la fila.
>
> **Pendiente, y por qué:**
>
> - **Fila 2 (llamar desde la propia hoja): DESCARTADA** por el propietario el
>   27/08/2026 — «esto no hay que hacerlo, desde la ficha». Se llama desde la
>   ficha, que está a un toque desde la hoja. No se retoma sin que lo pida.
>   Queda escrito para que nadie lo proponga otra vez creyendo que es una mejora.
> - **Fila 4 (skeletons): OBSOLETA, no pendiente.** Revisada el 28/08/2026. La
>   premisa era «la CARGA fría no dice nada durante 2-4 s», y eso ya no es
>   cierto: `bindLoadingLinks()` de `js/stic-ui.js` (plan 030) enseña el overlay
>   **al pulsar el enlace**, delegado en `document`, y además avisa a la app por
>   el puente para que pinte su indicador nativo. La espera está cubierta en la
>   página que se DEJA.
>
>   Y un skeleton no cabría aunque se quisiera: esto son recargas completas
>   servidas por WordPress, así que el navegador no pinta NADA de la pantalla de
>   destino hasta que llega el HTML entero. Un skeleton en el destino sería
>   código muerto por definición.
>
>   Lo que sí sirve es bajar la espera, y eso es lo que se hizo: marcar pasó de
>   13 llamadas a 10, la ficha del participante de 13 esperas a 7, y cambiar de
>   fecha cuesta 4 llamadas con la caché caliente. **No abrir esta fila otra
>   vez sin cambiar antes a navegación sin recarga**, que es otro melón.
> - **Fila 6 (buscador): HECHA A MEDIAS.** Hay buscador en el árbol de grupos
>   (`data-pl-filter`, se pinta a partir de 8 grupos) y filtra por código,
>   nombre, curso y **nombres de los monitores** — «el de Mercedes» funciona.
>   Lo que NO hace es lo que pedía esta fila: buscar a **UN chaval por su
>   nombre** y saltar a su ficha. «¿Cómo va Solete?» sigue sin resolverse.
>
>   Y no se resuelve aquí: es exactamente el mismo problema que **el navegador
>   de fichas sin pasar lista** (ROADMAP, «Melones pendientes»), que está por
>   hablar con el propietario. Cuando se decida aquello, esta fila se cierra con
>   ello o desaparece.

## Lo que YA está bien (no tocar, no re-inventar)

- Portada con CTA «hero» al marcar de tu grupo → el camino sábado es 2 toques.
- Selector de sesión nativo en la propia pantalla de marcar.
- «Han venido todos», ciclo de toque simple, hoja de estados con motivo.
- Borrador local a cada toque + cola offline.
- Botón refrescar real en las cuatro pantallas + «Ya lo he arreglado».
- Ficha accesible desde cada fila de marcar.

## Las filas, por orden de dolor

### 1. El guardado no puede perder marcas ni mentir (P1, con 033)

- Borrador: borrarlo SOLO al confirmar éxito el servidor (hoy se borra en el
  `submit`, antes de saber nada — detalle ya recogido en 033 §2.3). En fallo,
  las filas se repueblan del borrador y un aviso claro dice qué pasó.
- Tras guardar bien: confirmación inequívoca (check grande + háptico, ya hay
  `haptic()`) y **siguiente acción a un toque**: «Ver resumen» / «Otro grupo»
  / «Ficha de los ausentes» si hubo faltas. Hoy la pantalla re-renderiza igual
  y la confirmación es un párrafo pequeño arriba.
- El aviso de «guardado en el móvil, se enviará solo» (cola offline) debe
  distinguirse visualmente MUCHO del «guardada en el CRM»: hoy ambos son un
  aviso de texto; el primero es una promesa, el segundo un hecho.
- La cola offline tiene un agujero conocido: un reenvío con nonce caducado
  vuelve 200-con-aviso y la entrada se da por enviada (se pierde). Con el ring
  de 033 §2.2 el servidor lo detectaría; el JS debe comprobar en la respuesta
  una señal de éxito real (p. ej. `data-pl-saved-ok`) antes de sacar de la
  cola, no solo `res.ok`.

### 2. Ausentes → acción inmediata (P1)

Cuando se marca una falta (o la racha llega al umbral), lo interesante está a
demasiados toques. En la hoja del estado (donde ya se escribe el motivo):

- Botón «Llamar a casa» (`tel:`) y «WhatsApp» con el teléfono de la familia,
  si existe (el dato ya se carga para la ficha; traerlo a la hoja no añade
  llamadas al CRM si viaja en el HTML de la fila).
- ✅ **Hecho el 28/08/2026, la parte de la sesión:** el enlace a la ficha desde
  cada fila de la lista lleva ya `&sesion=`, y el alta de aviso la escribe en
  `stic_sessions_id_c`. Un aviso puesto un sábado queda atado a ESE sábado. Lo
  que no se hace —y no se va a hacer— es poner el aviso desde la propia hoja:
  se pone desde la ficha, que está a un toque.

### 3. La ficha como «tarjeta de sábado» (P1)

La ficha existe y es completa; lo que falta es jerarquía de urgencia. Arriba
del todo, sin scroll, lo que un monitor necesita EN la sesión: teléfono(s) de
la familia tocables (`tel:`/WhatsApp), alergias/observaciones si las hay,
racha de ausencias, avisos abiertos. Después el resto (pañuelo, historial,
familia). Misma idea en la ficha de monitor para coordinación (COORDINACION
§4: lo esencial, no la ficha entera).

### 4. Percepción de velocidad (P2, engancha con 034) — ❌ OBSOLETA

> **Cerrada el 28/08/2026 sin hacer, y a propósito.** El texto de abajo se
> queda como estaba escrito; lo que ha cambiado es la realidad que describe.
>
> «La CARGA fría no dice nada durante 2-4 s» ya no es verdad:
> `bindLoadingLinks()` enseña el overlay al pulsar el enlace y avisa a la app.
> Y un skeleton en el destino es imposible en un sitio de recargas completas:
> el navegador no pinta nada hasta que llega el HTML. Ver el bloque de estado
> de arriba.

- Skeletons (contornos grises) en árbol/marcar/resumen mientras llega el CRM,
  en vez de página en blanco: la webview ya enseña el overlay al ENVIAR
  (`stic-loading-form`), pero la CARGA fría no dice nada durante 2-4 s.
- Mantener el prefetch de 030 y, tras 034 fase 2, revisar si el skeleton casi
  nunca llega a verse (objetivo).

### 5. Menos toques residuales (P2)

- En el árbol, el toque en un grupo va a marcar (bien); añadir gesto/acceso
  secundario visible a la FICHA del grupo (hoy hay que saberlo).
- En resumen, cada tarjeta de sesión → toque = abrir esa lista en marcar
  (ida y vuelta de corrección en 2 toques).
- Monitores/coordinación: acceso a «pasar lista de monitores» desde la
  portada ya existe; revisar que reuniones ↔ monitores ↔ seguimientos se
  crucen entre sí sin volver a la portada.

### 6. Búsqueda de persona para coordinación (P3) — 🟡 a medias

Coordinación llega buscando a UN chaval («¿cómo va Solete?»). Un buscador
simple (typeahead sobre los datos ya cacheados de `struct`, sin llamadas
extra) en portada/árbol que salte directo a la ficha.

## Método de trabajo

- Una fila por PR, con captura antes/después (2 temas) en el PR.
- Nada de librerías nuevas: el JS del plugin es vanilla y así se queda.
- Todo botón nuevo pasa por la neutralización §0.b (trampa §3.5); todo color
  por tokens (trampa §3.4).
- Los textos, en el tono ya establecido (claro, sin tecnicismos, sin culpar
  al monitor).

## STOP conditions

- STOP si una fila pide un dato que suponga llamada nueva al CRM por fila
  pintada (1+N): rediseñar con los cargadores de colección o dejarla.
- STOP si el deep-link de avisos o los teléfonos exponen datos a un rol que
  no debe verlos (monitor vs coordinación: COORDINACION §2).
- El contrato con la app (webview) manda sobre cualquier gesto nuevo:
  `CONTRATO-APP-WEBVIEW.md` antes de tocar navegación/gestos.
