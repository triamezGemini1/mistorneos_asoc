# Despliegue: estructura asociaciones / particulares / torneos

Los cambios están en la rama `feature-final-unification` (commit `85f033f` y posteriores).  
Si en el servidor **no ves** el menú *Estructura → Org. particulares / Torneos asociaciones / Torneos particulares*, **no se subió el código** o **falta SQL**.

**Sincronización automática con beta:** ver `README_SYNC_BETA.md` (GitHub Actions + FTP a `mistorneos_beta`).

---

## Paso 1 — Backup

```bash
mysqldump -u USUARIO -p NOMBRE_BD > backup_antes_estructura.sql
```

---

## Paso 2 — Base de datos (en este orden)

En phpMyAdmin, seleccione la base correcta (`laestaci1_mistorneos` o `laestaci1_mistorneos_beta`) y ejecute:

1. `sql/migracion_estructura_organizaciones_2026.sql` — crea `tipo_org` si no existe  
2. `sql/fix_cod_org_organizaciones_particulares.sql` — corrige `cod_org` de particulares  
3. (Opcional, si no se aplicó antes) `sql/migracion_produccion_2026.sql`

Comprobación:

```sql
SHOW COLUMNS FROM organizaciones LIKE 'tipo_org';
SELECT tipo_org, COUNT(*) FROM organizaciones GROUP BY tipo_org;
```

---

## Paso 3 — Subir archivos PHP

**Opción A — Paquete completo (~34 MB)**  
`mistorneos_produccion_YYYYMMDD_HHMMSS.zip` (generar con `php scripts/crear_paquete_produccion.php`)

**Opción B — Solo estructura (~pocos MB)**  
`estructura_organizaciones_YYYYMMDD.zip` (generar con `php scripts/crear_paquete_estructura.php`)

Descomprimir en la **raíz** del sitio (misma carpeta que `config/`, `public/`, `modules/`, `lib/`).

**No sobrescribir** `.env` del servidor.

---

## Paso 4 — Verificación en el navegador

1. `public/check_env.php` → `tipo_org: sí`, carpetas storage OK  
2. Login admin general  
3. Menú lateral **Estructura** debe mostrar:
   - Torneos — Asociaciones  
   - Reporte asociaciones  
   - **Org. particulares**  
   - Torneos — Particulares  
   - Reporte particulares  
4. URLs de prueba:
   - `index.php?page=organizaciones_particulares`
   - `index.php?page=torneos_estructura&context=asociaciones`
   - `index.php?page=torneos_estructura&context=particulares&vista=reporte`

---

## Paso 5 — `.env` en servidor (recomendado en beta)

```env
MODERN_HOME=false
APP_ENV=production
```

---

## Archivos críticos del cambio estructural

| Ruta |
|------|
| `lib/OrganizacionDashboardStats.php` |
| `lib/TorneosEstructuraService.php` |
| `lib/ClubHelper.php` |
| `modules/organizaciones_particulares.php` |
| `modules/organizaciones/listado_particulares.php` |
| `modules/torneos_estructura.php` |
| `modules/torneos_estructura/lista.php` |
| `modules/torneos_estructura/reporte.php` |
| `modules/organizaciones.php`, `entidades.php`, `mi_organizacion.php` |
| `modules/organizaciones/listado_entidades.php`, `org_detail.php` |
| `public/includes/layout.php` |
| `public/index.php` |
| `config/auth.php` |

---

## Si sigue sin verse en producción

- Confirmar ruta FTP: `/public_html/mistorneos_beta/` (o producción real), no solo `public/`.  
- Limpiar caché del navegador (Ctrl+F5).  
- Comparar fecha de modificación de `public/includes/layout.php` en el servidor con la local.  
- Borrar `check_env.php` y `diagnose_home.php` tras validar.
