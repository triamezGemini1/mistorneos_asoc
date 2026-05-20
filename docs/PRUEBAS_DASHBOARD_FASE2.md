# Pruebas Dashboard Fase 2 — Guía por nivel de acceso

## Requisitos previos

| Requisito | Valor |
|-----------|--------|
| PHP | **8.2+** (WAMP: `C:\wamp64\bin\php\php8.2.18\php.exe`) |
| MySQL | Base `mistorneos` (ver `.env`) |
| Flag | `MODERN_HOME=false` por defecto (dashboard legacy). En `.env`: `MODERN_HOME=true` para vistas Tailwind Fase 2 |
| WAMP | Apache + MySQL en ejecución |

### Preparar credenciales (una vez)

Crea usuarios nuevos en **cualquier** base `mistorneos`:

```powershell
C:\wamp64\bin\php\php8.2.18\php.exe scripts\seed_test_access_all_levels.php
```

Contraseña: **`MistorneosTest2026`** — usuarios: `test_federacion`, `test_asociacion`, `test_torneo`, `test_operador`, `test_atleta`.

Listar usuarios en BD:

```powershell
C:\wamp64\bin\php\php8.2.18\php.exe scripts\list_dashboard_test_users.php
```

---

## URLs

| Recurso | URL local típica |
|---------|------------------|
| Login | http://localhost/mistorneos/public/login.php |
| Dashboard moderno | http://localhost/mistorneos/public/index.php?page=home |
| Demo layout (Fase 0) | http://localhost/mistorneos/public/index.php?page=_demo_modern |

Si usas otro virtual host, ajusta según `URL_BASE` / `BASE_PATH` en `.env`.

---

## Mapa rol → contexto → vista

| Nivel | Rol en sesión | `Context::resolve()` | Vista Tailwind |
|-------|---------------|----------------------|----------------|
| **Federación** | `admin_general` | `Federacion` | `views/modules/Dashboard/federacion.php` |
| **Asociación** | `admin_club` (org territorial, no FVD) | `Asociacion` | `views/modules/Dashboard/asociacion.php` |
| **Club** | `admin_torneo` u `operador` (con `club_id`) | `Club` | `views/modules/Dashboard/club.php` |
| **Invitado** | `usuario` | `Invitado` | Legacy `admin_dashboard.php` (sin Fase 2) |

**Importante:** Un `admin_club` cuya organización se llame *FEDERACIÓN VENEZOLANA DE DOMINÓ* cae en **Federación**, no en Asociación (`Context::classifyOrganizacion`). Para probar Asociación use `damazava` o `Addccaracas`, no `evguacara`.

---

## Datos de acceso por nivel

Tras ejecutar `seed_test_access_all_levels.php` (recomendado):

### 1. Federación (`admin_general`)

| Usuario | Contraseña |
|---------|------------|
| `test_federacion` | `MistorneosTest2026` |

---

### 2. Asociación (`admin_club`)

| Usuario | Contraseña | Organización |
|---------|------------|--------------|
| `test_asociacion` | `MistorneosTest2026` | ASOCIACION PRUEBA MIS TORNEOS |

---

### 3. Club / torneo (`admin_torneo` / `operador`)

| Usuario | Contraseña |
|---------|------------|
| `test_torneo` | `MistorneosTest2026` |
| `test_operador` | `MistorneosTest2026` |

---

### 4. Invitado / atleta (`usuario`)

| Usuario | Contraseña |
|---------|------------|
| `test_atleta` | `MistorneosTest2026` |

---

## Matriz de prueba manual (checklist)

```
[ ] Login Trinoamez → home muestra «Panel nacional» + 4 métricas
[ ] Rankings: datos o «Sin datos»
[ ] Login damazava → título asociación + 3 KPIs + tabla clubes
[ ] Login Addccaracas → mismos bloques con otros totales
[ ] Login test_admin_torneo → bloque mesas + pendientes
[ ] Sin torneo activo: acciones rápidas deshabilitadas
[ ] Cerrar sesión y probar usuario ramaguza → dashboard legacy (no Tailwind Fase 2)
```

---

## Esquema local detectado (referencia)

En la instalación actual:

- `organizaciones.tipo_org` y `organizaciones.cod_org`: **no presentes** → Q3 usa fallback; territorialidad vía `OrganizacionDashboardStats` con `entidad`.
- Torneo activo de ejemplo: `#12` «Torneo Nacional de Domino» (`fechator` 2026-05-30, `club_responsable=7`).

Si las métricas de Club salen vacías, asigne un torneo activo al `club_responsable` del club 4 o cambie `club_id` en el seed a un club con torneos en curso.

---

## Solución de problemas

| Síntoma | Causa probable | Acción |
|---------|----------------|--------|
| Error Composer PHP 7.4 | CLI usa PHP antiguo | Usar `php8.2.18` explícito |
| Login falla tras seed | Caché sesión | Cerrar sesión / ventana privada |
| Asociación muestra Federación | Nombre org = FVD | Usar `damazava` / `Addccaracas` |
| Club sin mesas | Sin `partiresul` ni torneo activo del club | Ver torneos Q7; revisar fallback `mesas_asignacion` |
| Métricas en 0 | BD sin datos en ámbito | Normal en entorno vacío; validar «Sin datos» en tablas |

---

## Contraseña histórica (solo referencia)

| Usuario | Contraseña documentada en repo | Script |
|---------|-------------------------------|--------|
| `Trinoamez` | `npi$2025` | `scripts/normalize_admin_trinoamez.php` |
| Usuarios seed masivos | `npi2025` | `seed_usuarios_prueba.php` |

Tras `seed_dashboard_test_access.php`, las cuentas de la tabla superior usan **`MistorneosTest2026`**.
