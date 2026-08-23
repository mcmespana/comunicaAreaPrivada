/**
 * Cliente mínimo del API V8 de SinergiaCRM (JSON:API) para el Guardián.
 * ---------------------------------------------------------------------------
 * Es el mismo trato que hace .github/scripts/cumples/enviar-cumples.mjs, sacado
 * a su propio fichero porque ahora lo usan dos cosas. Sin dependencias: solo
 * `fetch`, que Node 20 ya trae.
 *
 * Variables de entorno:
 *   CRM_URL            https://movimientoconsolacion.sinergiacrm.org
 *   CRM_CLIENT_ID      id del cliente OAuth2 del API V8
 *   CRM_CLIENT_SECRET  su secreto
 */

const TIEMPO_MAXIMO_MS = 45_000;

/**
 * fetch con reintentos. Solo reintenta lo que tiene sentido reintentar (429,
 * 5xx y fallos de red): un 401 o un 400 no se arreglan insistiendo.
 */
export async function fetchConReintentos(url, opciones = {}, intentos = 3, avisar = () => {}) {
  let ultimoError;
  for (let i = 1; i <= intentos; i++) {
    try {
      const resp = await fetch(url, { ...opciones, signal: AbortSignal.timeout(TIEMPO_MAXIMO_MS) });
      if (resp.status === 429 || resp.status >= 500) {
        if (i === intentos) return resp;
        const espera = 1500 * i;
        avisar(`HTTP ${resp.status} en ${url} — reintento ${i}/${intentos - 1} en ${espera} ms`);
        await new Promise((r) => setTimeout(r, espera));
        continue;
      }
      return resp;
    } catch (err) {
      ultimoError = err;
      if (i === intentos) break;
      await new Promise((r) => setTimeout(r, 1500 * i));
    }
  }
  throw ultimoError;
}

/**
 * Token OAuth2 por client_credentials.
 *
 * Se intenta primero en JSON y, si el CRM lo rechaza, en formulario: según la
 * versión de SuiteCRM acepta uno u otro y no merece la pena adivinar.
 */
async function pedirToken({ url, clientId, clientSecret }) {
  const cuerpo = {
    grant_type: 'client_credentials',
    client_id: clientId,
    client_secret: clientSecret,
    scope: '',
  };

  const intentos = [
    { 'Content-Type': 'application/json', Accept: 'application/json' },
    { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
  ];

  let ultimo = '';
  for (const headers of intentos) {
    const body = headers['Content-Type'] === 'application/json'
      ? JSON.stringify(cuerpo)
      : new URLSearchParams(cuerpo).toString();

    const resp = await fetchConReintentos(`${url}/Api/access_token`, { method: 'POST', headers, body });
    const texto = await resp.text();
    if (resp.ok) {
      const datos = JSON.parse(texto);
      if (!datos.access_token) throw new Error(`El CRM no devolvió access_token: ${texto.slice(0, 300)}`);
      return datos.access_token;
    }
    ultimo = `HTTP ${resp.status} ${texto.slice(0, 300)}`;
  }

  throw new Error(`No se pudo autenticar en el CRM (${ultimo})`);
}

/** Aplana un registro JSON:API: el id va fuera de "attributes". */
function aplanar(fila) {
  return { id: fila.id, ...(fila.attributes ?? {}) };
}

/**
 * Abre una sesión con el CRM y devuelve los cuatro métodos que hacen falta.
 *
 * `avisar` recibe los mensajes de reintento para que el guardián los pueda
 * meter en su informe en vez de perderlos en el log.
 */
export async function abrirCrm({ url, clientId, clientSecret, avisar = () => {} } = {}) {
  const base = (url ?? '').replace(/\/+$/, '');
  if (!base || !clientId || !clientSecret) {
    throw new Error('Faltan CRM_URL, CRM_CLIENT_ID o CRM_CLIENT_SECRET.');
  }

  const token = await pedirToken({ url: base, clientId, clientSecret });
  const cabeceras = { Authorization: `Bearer ${token}`, Accept: 'application/vnd.api+json' };
  let llamadas = 0;

  async function pedir(ruta, opciones = {}) {
    llamadas += 1;
    const resp = await fetchConReintentos(`${base}${ruta}`, {
      ...opciones,
      headers: { ...cabeceras, ...(opciones.headers ?? {}) },
    }, 3, avisar);
    const texto = await resp.text();
    if (!resp.ok) {
      throw new Error(`El CRM respondió HTTP ${resp.status} a ${ruta}: ${texto.slice(0, 400)}`);
    }
    return texto ? JSON.parse(texto) : {};
  }

  return {
    get llamadas() { return llamadas; },

    /** Todos los campos de un módulo. Sirve para comprobar que existen. */
    async camposDe(modulo) {
      const datos = await pedir(`/Api/V8/meta/fields/${modulo}`);
      const attrs = datos?.data?.attributes ?? datos?.data ?? {};
      return Object.keys(attrs);
    },

    /** Recorre todas las páginas de un módulo. `campos` es un sparse fieldset. */
    async listar(modulo, { campos = [], tamanoPagina = 50, maxPaginas = 200 } = {}) {
      const registros = [];
      let pagina = 1;
      let totalPaginas = 1;

      do {
        const params = new URLSearchParams();
        if (campos.length) params.set(`fields[${modulo}]`, campos.join(','));
        params.set('page[number]', String(pagina));
        params.set('page[size]', String(tamanoPagina));

        const datos = await pedir(`/Api/V8/module/${modulo}?${params}`);
        for (const fila of datos.data ?? []) registros.push(aplanar(fila));

        // El CRM deja de mandar total-pages cuando no hay más; el `|| pagina`
        // evita quedarse dando vueltas si algún día cambia el formato.
        totalPaginas = Number(datos.meta?.['total-pages'] ?? datos.meta?.total_pages ?? pagina) || pagina;
        pagina += 1;
      } while (pagina <= totalPaginas && pagina <= maxPaginas);

      return registros;
    },

    /** Los registros relacionados con uno dado a través de un link field. */
    async relaciones(modulo, id, link, { maxPaginas = 40 } = {}) {
      const registros = [];
      let pagina = 1;
      let totalPaginas = 1;

      do {
        const params = new URLSearchParams({ 'page[number]': String(pagina), 'page[size]': '50' });
        const datos = await pedir(`/Api/V8/module/${modulo}/${id}/relationships/${link}?${params}`);
        for (const fila of datos.data ?? []) registros.push(aplanar(fila));
        totalPaginas = Number(datos.meta?.['total-pages'] ?? datos.meta?.total_pages ?? pagina) || pagina;
        pagina += 1;
      } while (pagina <= totalPaginas && pagina <= maxPaginas);

      return registros;
    },

    /** Actualiza campos de un registro. */
    async actualizar(modulo, id, atributos) {
      return pedir('/Api/V8/module', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/vnd.api+json' },
        body: JSON.stringify({ data: { type: modulo, id, attributes: atributos } }),
      });
    },
  };
}
