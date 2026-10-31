<#
.SYNOPSIS
  Remove the portable dev environment completely: stops it, then deletes
  <repo>\.devenv (PHP, MariaDB, the demo database, downloads) and app\vendor
  (Composer packages). Nothing else on the PC was changed by setup.ps1.
#>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\common.ps1"

& "$PSScriptRoot\stop.ps1"
foreach ($path in $DevDir, (Join-Path $RepoRoot 'app\vendor')) {
    if (Test-Path $path) {
        Remove-Item $path -Recurse -Force
        Write-Host "Removed $path"
    }
}
Write-Host "Dev environment removed." -ForegroundColor Green
