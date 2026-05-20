# Empaquetado producción (Windows / PowerShell)
# Uso: .\scripts\deploy-package.ps1
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$php = "C:\wamp64\bin\php\php8.2.18\php.exe"
if (-not (Test-Path $php)) { $php = "php" }
& $php "$Root\scripts\crear_paquete_produccion.php"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Write-Host ""
Write-Host "Alternativa con Git Bash: bash scripts/deploy-package.sh"
