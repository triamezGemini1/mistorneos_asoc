# Sube cambios a GitHub y dispara el deploy FTP a mistorneos_beta (Actions).
# Uso: .\scripts\sync-beta.ps1 [-Message "mi mensaje"] [-Branch feature-final-unification]
param(
    [string]$Message = "Sync beta: actualización desde local",
    [string]$Branch = "feature-final-unification"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

Write-Host "=== Sync beta (GitHub Actions -> mistorneos_beta) ===" -ForegroundColor Cyan
Write-Host "Rama: $Branch"
Write-Host ""

$status = git status --porcelain
if (-not $status) {
    Write-Host "No hay cambios locales. Haciendo push por si hay commits pendientes..." -ForegroundColor Yellow
} else {
    git add -A
    git commit -m $Message
}

git push origin $Branch
if ($LASTEXITCODE -ne 0) {
    Write-Host "Push falló. Revise remoto y credenciales Git." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Push OK. Abra GitHub Actions y espere 'Despliegue de PRUEBAS (BETA)' en verde." -ForegroundColor Green
Write-Host "https://github.com/triamezGemini1/mistorneos/actions"
Write-Host "Beta: https://laestaciondeldominohoy.com/mistorneos_beta/public/check_env.php"
