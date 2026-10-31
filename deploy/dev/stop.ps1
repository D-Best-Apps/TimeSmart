<#
.SYNOPSIS
  Stop the portable dev environment's PHP web server and MariaDB.
  Only processes started from <repo>\.devenv are stopped.
#>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\common.ps1"

$ours = Get-Process -ErrorAction SilentlyContinue | Where-Object {
    $_.Path -and $_.Path.StartsWith($DevDir, [StringComparison]::OrdinalIgnoreCase)
}
foreach ($p in $ours) {
    if ($p.ProcessName -like 'mariadbd*' -and (Test-DevDb)) {
        # Clean shutdown so the data files are consistent
        & $MariaExe @(Get-DbArgs) -e "SHUTDOWN" 2>$null
        $p.WaitForExit(15000) | Out-Null
    }
    if (-not $p.HasExited) {
        Stop-Process -Id $p.Id -Force
    }
    Write-Host "Stopped $($p.ProcessName) ($($p.Id))"
}
if (-not $ours) { Write-Host "Nothing running." }
