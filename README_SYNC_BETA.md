# Sincronización local → beta en la nube

La app en **https://laestaciondeldominohoy.com/mistorneos_beta/** se actualizaba por **GitHub Actions** (FTP automático al hacer `push`), no por subir solo `public/`.

---

## Por qué dejó de sincronizarse

| Causa | Detalle |
|-------|---------|
| Rama incorrecta | El deploy beta solo corre con push a **`develop`** o **`mistorneos_beta`**. Si trabajas en `feature-final-unification` u otra rama, **no se sube nada**. |
| SFTP mal configurado | `.vscode/sftp.json` apuntaba a `/public_html/mistorneos` (producción), no a `mistorneos_beta`. |
| Sin push a GitHub | Cambios solo en WAMP local, sin `git push`, no activan Actions. |
| Secretos GitHub | Si caducaron FTP o se borraron `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` en el repo, el workflow falla (ver pestaña Actions). |

---

## Mecanismo 1 — Automático (recomendado)

**Archivo:** `.github/workflows/deploy-beta.yml`

1. Haces commit en local.
2. Push a una rama que dispare el workflow:
   - `develop`
   - `mistorneos_beta`
   - `feature-final-unification` (añadido para recuperar el flujo actual)
3. En GitHub: **Actions → "Despliegue de PRUEBAS (BETA)"** → debe quedar en verde.
4. Los archivos llegan a `public_html/mistorneos_beta/` (sin sobrescribir `.env` del servidor).

**Deploy manual** (sin esperar un push):

- GitHub → **Actions** → **Despliegue de PRUEBAS (BETA)** → **Run workflow** → elegir rama → Run.

### Secretos necesarios en GitHub

Repo → **Settings → Secrets and variables → Actions**:

| Secreto | Ejemplo |
|---------|---------|
| `FTP_SERVER` | `laestaciondeldominohoy.com` |
| `FTP_USERNAME` | usuario FTP cPanel |
| `FTP_PASSWORD` | contraseña FTP |

Sin estos tres, el workflow falla en el primer paso FTP.

### Flujo habitual de trabajo

```bash
git add .
git commit -m "Descripción del cambio"
git push origin feature-final-unification
# o: git push origin develop
```

Tras el push, espera 2–5 minutos y recarga la beta con Ctrl+F5.

---

## Mecanismo 2 — SFTP al guardar (Cursor / VS Code)

**Plantilla:** `.vscode/sftp.json.example` (copiar a `sftp.json`, que no va a git).

- Perfil **Beta**: `remotePath` = `/public_html/mistorneos_beta`
- Perfil **Producción**: `remotePath` = `/public_html/mistorneos`

`uploadOnSave: true` sube el archivo al guardar. Útil para un hotfix puntual; el flujo oficial sigue siendo GitHub Actions.

**No subas** `.env` ni credenciales por SFTP.

---

## Mecanismo 3 — ZIP manual (respaldo)

```bash
php scripts/crear_paquete_produccion.php
# o solo estructura:
php scripts/crear_paquete_estructura.php
```

Subir por FileZilla/cPanel a `public_html/mistorneos_beta/` y ejecutar SQL en phpMyAdmin (`DEPLOY_ESTRUCTURA_ORGANIZACIONES.md`).

---

## Producción vs beta

| Entorno | Carpeta FTP | Rama GitHub (workflow `deploy.yml`) |
|---------|-------------|-------------------------------------|
| **Beta** | `public_html/mistorneos_beta/` | `main` no — usar `deploy-beta.yml` |
| **Producción** | `public_html/mistorneos/` | push a **`main`** → `deploy.yml` |

No mezclar: un push a `main` actualiza producción, no beta.

---

## Comprobar que la beta recibió el código

1. `https://laestaciondeldominohoy.com/mistorneos_beta/public/check_env.php`
2. Menú **Estructura** con Org. particulares y torneos por contexto.
3. En cPanel, fecha de modificación de `public/includes/layout.php` reciente.

---

## SQL tras un deploy grande

En phpMyAdmin (BD `laestaci1_mistorneos_beta`):

1. `sql/migracion_estructura_organizaciones_2026.sql`
2. `sql/fix_cod_org_organizaciones_particulares.sql`

Ver `DEPLOY_ESTRUCTURA_ORGANIZACIONES.md`.
