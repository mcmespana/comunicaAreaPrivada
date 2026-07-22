# Despliegue a producción (CI/CD)

Cómo se publica el plugin en **comunica.movimientoconsolacion.com** automáticamente.

---

## 1. Cómo funciona

- Hay una rama de **producción** (`production`) que representa lo que está
  publicado en el sitio real.
- Cuando se hace **push** a esa rama (normalmente al **mergear una Pull Request** ahí), se
  dispara el workflow [`.github/workflows/deploy-produccion.yml`](../.github/workflows/deploy-produccion.yml).
- El workflow **sube el plugin por FTPS** a la carpeta del plugin en el hosting, de forma
  **incremental** (solo los archivos que han cambiado).
- También se puede lanzar **a mano** desde la pestaña **Actions → Deploy a producción → Run workflow**.

```
   PR aprobada ──merge──►  rama production  ──push──►  GitHub Action  ──FTPS──►  hosting WordPress
                                                                                wp-content/plugins/<plugin>/
```

> El resto de ramas (`main`, ramas de trabajo) **no despliegan nada**. Solo `production`.

---

## 2. Secretos de GitHub que tienes que crear

En GitHub: **repositorio → Settings → Secrets and variables → Actions → New repository secret**.

Crea estos **4 secretos** (método FTPS, el configurado por defecto):

| Secreto | Qué poner | Ejemplo |
|---------|-----------|---------|
| `FTP_SERVER` | Host FTP del hosting | `ftp.movimientoconsolacion.com` (o la IP que te dé el hosting) |
| `FTP_USERNAME` | Usuario FTP | `comunica@movimientoconsolacion.com` o el que te den |
| `FTP_PASSWORD` | Contraseña de ese usuario FTP | *(la contraseña)* |
| `FTP_SERVER_DIR` | Carpeta del plugin en el servidor, **acabada en `/`** | `/public_html/wp-content/plugins/sinergiacrm-private-area/` |

### Notas importantes
- **`FTP_SERVER_DIR` debe terminar en `/`.** Apunta a la carpeta del plugin (la que contiene
  `sinergiacrm-private-area.php`). Si no sabes el nombre exacto de la carpeta, míralo por FTP dentro
  de `wp-content/plugins/`.
- Si la ruta raíz del hosting no es `public_html`, ajústala (a veces es `httpdocs`, `www`, `htdocs`…).
- Usa un **usuario FTP dedicado** si puedes, con acceso solo a `wp-content/plugins/` (más seguro que
  el usuario maestro).

---

## 3. Crear la rama `production` (una sola vez)

Si aún no existe, créala desde la rama que quieras publicar (normalmente `main`):

- Desde la web de GitHub: selector de ramas → escribe `production` → *Create branch*.
- O por consola:
  ```bash
  git checkout main
  git pull
  git checkout -b production
  git push -u origin production
  ```

> ⚠️ La **primera vez** que hagas push a `production`, el workflow intentará desplegar. Asegúrate de
> tener los 4 secretos creados **antes**, o ese primer run fallará (sin consecuencias: solo reintenta).

---

## 4. Flujo de trabajo recomendado

1. Desarrollas en una rama de trabajo y abres PR hacia **`main`**.
2. Se revisa y se mergea a `main` (esto **no** despliega).
3. Cuando quieras **publicar**, llevas `main` a `production`:
   - Abre una PR de `main` → `production` y mergéala, **o**
   - lanza el deploy a mano desde **Actions** si ya tienes `production` al día.
4. El push a `production` despliega por FTPS. Lo ves en la pestaña **Actions**.

Así `production` siempre refleja exactamente lo que está publicado, y desplegar es **un merge**.

---

## 5. ¿Tu acceso es SFTP de verdad (puerto 22), no FTPS?

`SFTP` (basado en SSH, puerto 22) y `FTPS` (FTP sobre TLS, puerto 21) **no son lo mismo**. El
workflow por defecto usa **FTPS**. Si tu hosting solo da **SFTP por SSH**:

1. En `deploy-produccion.yml`, sustituye el paso *"Subir el plugin por FTPS"* por el bloque SFTP que
   está comentado al final del archivo.
2. Crea estos secretos en vez de los `FTP_*`:

| Secreto | Qué poner |
|---------|-----------|
| `SFTP_SERVER` | Host SSH/SFTP |
| `SFTP_USERNAME` | Usuario SSH |
| `SFTP_PRIVATE_KEY` | Clave **privada** SSH (contenido completo del archivo) con acceso al hosting |
| `SFTP_REMOTE_DIR` | Ruta de la carpeta del plugin |

> Para la clave: genera un par con `ssh-keygen -t ed25519`, sube la **pública** al hosting
> (`~/.ssh/authorized_keys`) y guarda la **privada** en el secreto `SFTP_PRIVATE_KEY`.

---

## 6. Resolución de problemas

- **Falla la conexión:** revisa `FTP_SERVER` (sin `ftp://` delante) y que el hosting permita FTPS.
  Algunos hostings usan un host distinto para FTP que para la web.
- **Sube a la carpeta equivocada:** revisa `FTP_SERVER_DIR` (ruta completa + `/` final).
- **Tarda mucho la primera vez:** normal; el primer deploy sube todo. Los siguientes son incrementales
  (la action guarda un `.ftp-deploy-sync-state.json` en el servidor para saber qué cambió).
- **Quiero forzar resubida completa:** borra ese `.ftp-deploy-sync-state.json` del servidor y vuelve a
  lanzar el workflow.
