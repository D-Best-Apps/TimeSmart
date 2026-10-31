# Shared paths and helpers for the portable dev environment (dot-sourced by the
# other deploy\dev scripts). Everything lives in <repo>\.devenv.

$RepoRoot     = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$DevDir       = Join-Path $RepoRoot '.devenv'
$Downloads    = Join-Path $DevDir 'downloads'
$PhpDir       = Join-Path $DevDir 'php'
$PhpExe       = Join-Path $PhpDir 'php.exe'
$PhpIni       = Join-Path $DevDir 'php.ini'
$ComposerPhar = Join-Path $DevDir 'composer.phar'
$MariaDir     = Join-Path $DevDir 'mariadb'
$MysqldExe    = Join-Path $MariaDir 'bin\mariadbd.exe'
$MariaExe     = Join-Path $MariaDir 'bin\mariadb.exe'
$DataDir      = Join-Path $DevDir 'data'
$LogDir       = Join-Path $DevDir 'logs'

# Local-only demo credentials (MariaDB listens on 127.0.0.1 only)
$DbPort     = 3307
$DbName     = 'timeclock-demo'
$DbUser     = 'timeclock'
$DbPass     = 'timeclock-dev'
$DbRootPass = 'devroot'
$WebPort    = 8080

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }

function Get-DbArgs { @('-h', '127.0.0.1', "-P$DbPort", '-uroot', "-p$DbRootPass") }

function Test-DevDb {
    $c = New-Object Net.Sockets.TcpClient
    try { $c.Connect('127.0.0.1', $DbPort); return $true } catch { return $false } finally { $c.Dispose() }
}

function Start-DevDb {
    if (Test-DevDb) { return }
    Write-Step "Starting MariaDB on 127.0.0.1:$DbPort"
    # No output redirection: that would make MariaDB inherit (and hold open) this
    # console's pipes. It writes its own error log into the data directory.
    Start-Process -FilePath $MysqldExe -ArgumentList "--defaults-file=`"$(Join-Path $DataDir 'my.ini')`"", "--log-error=`"$(Join-Path $DataDir 'mariadb.err')`"" `
        -WindowStyle Hidden | Out-Null
    for ($i = 0; $i -lt 60 -and -not (Test-DevDb); $i++) { Start-Sleep -Milliseconds 500 }
    if (-not (Test-DevDb)) { throw "MariaDB did not start; see $DataDir\mariadb.err" }
}

# The app reads its database settings from $_ENV (see app/auth/db.php)
function Set-DevEnv {
    $env:DB_HOST = '127.0.0.1'
    $env:DB_NAME = $DbName
    $env:DB_USER = $DbUser
    $env:DB_PASS = $DbPass
    $env:DB_TIMEZONE = 'America/Chicago'
    $env:SESSION_COOKIE_SECURE = '0'   # plain http on the LAN; production keeps HTTPS-only cookies
}

function Get-LanAddresses {
    Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.PrefixOrigin -in 'Dhcp', 'Manual' } |
        Select-Object -ExpandProperty IPAddress
}
