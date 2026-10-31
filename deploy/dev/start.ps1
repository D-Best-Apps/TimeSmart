<#
.SYNOPSIS
  Start the portable dev environment: MariaDB in the background, then the PHP
  web server in this window (Ctrl+C stops the web server; stop.ps1 stops both).
  Listens on all interfaces so an iPad on the same Wi-Fi can open the kiosk.
#>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\common.ps1"

if (-not (Test-Path $PhpExe) -or -not (Test-Path $MysqldExe)) {
    throw "Run deploy\dev\setup.ps1 first."
}
Start-DevDb
Set-DevEnv

Write-Host ""
Write-Host "TimeSmart is running:" -ForegroundColor Green
Write-Host "  This PC:   http://localhost:$WebPort/"
foreach ($ip in Get-LanAddresses) {
    Write-Host "  iPad kiosk: http://${ip}:$WebPort/kiosk/"
}
Write-Host "  Demo logins: see docs\DEMO.md"
Write-Host "  If Windows asks whether php.exe may use the network, allow Private networks so the iPad can connect."
Write-Host "  Ctrl+C stops the web server; deploy\dev\stop.ps1 also stops MariaDB."
Write-Host ""

& $PhpExe -c $PhpIni -S "0.0.0.0:$WebPort" -t (Join-Path $RepoRoot 'app') (Join-Path $PSScriptRoot 'router.php')
