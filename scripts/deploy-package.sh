#!/usr/bin/env bash
# =============================================================================
# Empaquetado para producción — respeta .deployignore
# Uso (Git Bash / Linux / macOS):
#   bash scripts/deploy-package.sh
# Salida: mistorneos_produccion_YYYYMMDD_HHMMSS.zip en la raíz del proyecto
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DEPLOYIGNORE="${ROOT}/.deployignore"
STAMP="$(date +%Y%m%d_%H%M%S)"
ZIP_NAME="mistorneos_produccion_${STAMP}.zip"
ZIP_PATH="${ROOT}/${ZIP_NAME}"
LIST_FILE="$(mktemp)"
EXCLUDE_FILE="$(mktemp)"
trap 'rm -f "$LIST_FILE" "$EXCLUDE_FILE"' EXIT

echo "=== Empaquetado producción: ${ZIP_NAME} ==="
echo "Raíz: ${ROOT}"
echo ""

# Exclusiones obligatorias (además de .deployignore)
MANDATORY_EXCLUDE=(
  ".git"
  ".git/*"
  "node_modules"
  "node_modules/*"
  "vendor"
  "vendor/*"
  ".env"
  ".env.*"
  ".vscode"
  ".vscode/*"
  "confiprrod.php"
  "config/config.development.php"
  "config/env.production.php"
  "*.zip"
  "storage/logs/*"
  "storage/cache/*"
  "storage/sessions/*"
)

{
  for pat in "${MANDATORY_EXCLUDE[@]}"; do
    echo "$pat"
  done
  if [[ -f "$DEPLOYIGNORE" ]]; then
    grep -v '^[[:space:]]*#' "$DEPLOYIGNORE" | grep -v '^[[:space:]]*$' || true
  fi
} | sort -u > "$EXCLUDE_FILE"

# Construir lista de archivos con find (portable)
while IFS= read -r -d '' rel; do
  rel="${rel#./}"
  [[ -z "$rel" ]] && continue

  skip=0
  while IFS= read -r pat; do
    [[ -z "$pat" ]] && continue
    if [[ "$rel" == "$pat" ]] || [[ "$rel" == $pat ]] || [[ "$rel" == */$pat ]] || [[ "$rel" == $pat/* ]] || [[ "$rel" == */$pat/* ]]; then
      skip=1
      break
    fi
    case "$pat" in
      *\*) base="${pat%\*}"; [[ "$rel" == $base* ]] && skip=1 && break ;;
    esac
  done < "$EXCLUDE_FILE"
  (( skip )) && continue

  echo "$rel" >> "$LIST_FILE"
done < <(find . -type f ! -path './.git/*' ! -path './node_modules/*' ! -path './vendor/*' -print0)

# SQL obligatorios
SQL_REQUIRED=(
  "sql/migracion_produccion_2026.sql"
  "sql/fix_cod_org_organizaciones_particulares.sql"
)
for sql in "${SQL_REQUIRED[@]}"; do
  if [[ -f "${ROOT}/${sql}" ]]; then
    grep -qxF "$sql" "$LIST_FILE" 2>/dev/null || echo "$sql" >> "$LIST_FILE"
    echo "  ✓ Incluido: ${sql}"
  else
    echo "  ⚠ No encontrado: ${sql}" >&2
  fi
done

# Docs de despliegue
for doc in DEPLOY_PRODUCCION_2026.md DEPLOY_COMPLETO_PRODUCCION.md; do
  [[ -f "${ROOT}/${doc}" ]] && { grep -qxF "$doc" "$LIST_FILE" 2>/dev/null || echo "$doc" >> "$LIST_FILE"; }
done

COUNT="$(wc -l < "$LIST_FILE" | tr -d ' ')"
echo ""
echo "Archivos a empaquetar: ${COUNT}"

if ! command -v zip >/dev/null 2>&1; then
  echo "ERROR: 'zip' no está instalado. En Windows use Git Bash o: php scripts/crear_paquete_produccion.php" >&2
  exit 1
fi

rm -f "$ZIP_PATH"
# Crear zip desde la raíz del proyecto
(cd "$ROOT" && zip -q -r "$ZIP_PATH" -@ < "$LIST_FILE")

SIZE="$(du -h "$ZIP_PATH" | cut -f1)"
echo ""
echo "============================================================"
echo "✅ ZIP creado: ${ZIP_NAME}"
echo "   Ruta: ${ZIP_PATH}"
echo "   Tamaño: ${SIZE}"
echo "   Archivos: ${COUNT}"
echo "============================================================"
echo ""
echo "Post-despliegue: subir public/check_env.php y abrirlo en el navegador."
echo "IMPORTANTE: No sobrescribir el .env del servidor."
