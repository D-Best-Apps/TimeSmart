<#
.SYNOPSIS
  Portable Windows dev/demo environment for TimeSmart: PHP + MariaDB + Composer,
  all inside <repo>\.devenv (git-ignored). Nothing is installed system-wide;
  deploy\dev\uninstall.ps1 (or deleting .devenv and app\vendor) removes it all.

.DESCRIPTION
  1. Downloads the latest PHP 8.3 (NTS x64), MariaDB 11.4 LTS and Composer,
     checking each file's published SHA-256.
  2. Configures php.ini (extensions, env vars into $_ENV, MariaDB port).
  3. Initializes a MariaDB data dir bound to 127.0.0.1:3307, creates the
     `timeclock-demo` database, imports deploy\database\timeclock-schema.sql and
     records every migration as already applied (the schema is current).
  4. composer install in app\.
  5. Seeds demo data (deploy\scripts\seed_demo.php).
  Safe to re-run: finished steps are skipped. -Reseed drops and recreates the
  database with fresh demo data.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File deploy\dev\setup.ps1
  powershell -ExecutionPolicy Bypass -File deploy\dev\start.ps1
#>
param([switch]$Reseed)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest is far faster without the progress bar
. "$PSScriptRoot\common.ps1"

New-Item -ItemType Directory -Force -Path $DevDir, $Downloads | Out-Null

function Get-Verified([string]$Url, [string]$Sha256, [string]$Name) {
    $dest = Join-Path $Downloads $Name
    if ((Test-Path $dest) -and ((Get-FileHash $dest -Algorithm SHA256).Hash -eq $Sha256.ToUpper())) {
        return $dest
    }
    Write-Step "Downloading $Name"
    Invoke-WebRequest -Uri $Url -OutFile $dest -UseBasicParsing
    $actual = (Get-FileHash $dest -Algorithm SHA256).Hash
    if ($actual -ne $Sha256.ToUpper()) {
        Remove-Item $dest -Force
        throw "Checksum mismatch for $Name (expected $Sha256, got $actual)"
    }
    return $dest
}

function Expand-Into([string]$Zip, [string]$Target) {
    $tmp = Join-Path $DevDir ('_extract_' + [guid]::NewGuid().ToString('N'))
    Expand-Archive -Path $Zip -DestinationPath $tmp -Force
    $inner = Get-ChildItem $tmp
    # Some zips have a single top-level folder (MariaDB); unwrap it
    $src = if ($inner.Count -eq 1 -and $inner[0].PSIsContainer) { $inner[0].FullName } else { $tmp }
    if (Test-Path $Target) { Remove-Item $Target -Recurse -Force }
    Move-Item $src $Target
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
}

# ---------- PHP ----------
if (-not (Test-Path $PhpExe)) {
    $rel = (Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing).'8.3'.'nts-vs16-x64'.zip
    $zip = Get-Verified "https://windows.php.net/downloads/releases/$($rel.path)" $rel.sha256 $rel.path
    Write-Step "Extracting PHP"
    Expand-Into $zip $PhpDir
}
if (-not (Test-Path $PhpIni)) {
    Write-Step "Writing php.ini"
    $ini = Get-Content (Join-Path $PhpDir 'php.ini-development') -Raw
    foreach ($ext in 'curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'mysqli', 'openssl', 'sodium', 'zip') {
        $ini = $ini -replace "(?m)^;extension=$ext\s*$", "extension=$ext"
    }
    $ini = $ini -replace '(?m)^;\s*extension_dir = "ext"', 'extension_dir = "ext"'
    $ini = $ini -replace '(?m)^variables_order = .*$', 'variables_order = "EGPCS"'   # app reads DB_* from $_ENV
    $ini = $ini -replace '(?m)^;date\.timezone =.*$', 'date.timezone = America/Chicago'
    $ini = $ini -replace '(?m)^mysqli\.default_port = .*$', "mysqli.default_port = $DbPort"  # db.php passes no port
    $ini += "`r`n; TimeSmart dev`r`nmemory_limit = 512M`r`nupload_max_filesize = 8M`r`npost_max_size = 12M`r`n"
    Set-Content -Path $PhpIni -Value $ini -Encoding ASCII
}

# ---------- Composer ----------
if (-not (Test-Path $ComposerPhar)) {
    $sha = (Invoke-WebRequest -Uri 'https://getcomposer.org/download/latest-stable/composer.phar.sha256' -UseBasicParsing).Content.Trim().Split(' ')[0]
    $phar = Get-Verified 'https://getcomposer.org/download/latest-stable/composer.phar' $sha 'composer.phar'
    Copy-Item $phar $ComposerPhar
}

# ---------- MariaDB ----------
if (-not (Test-Path $MysqldExe)) {
    $api = Invoke-RestMethod -Uri 'https://downloads.mariadb.org/rest-api/mariadb/11.4/latest/' -UseBasicParsing
    $release = ($api.releases.PSObject.Properties | Select-Object -First 1).Value
    $file = $release.files | Where-Object { $_.file_name -like '*-winx64.zip' } | Select-Object -First 1
    $zip = Get-Verified $file.file_download_url $file.checksum.sha256sum $file.file_name
    Write-Step "Extracting MariaDB"
    Expand-Into $zip $MariaDir
}
if (-not (Test-Path (Join-Path $DataDir 'mysql'))) {
    Write-Step "Initializing MariaDB data directory"
    & (Join-Path $MariaDir 'bin\mariadb-install-db.exe') "--datadir=$DataDir" "--password=$DbRootPass" "--port=$DbPort" | Out-Host
    # Local only: never reachable from the network
    Add-Content -Path (Join-Path $DataDir 'my.ini') -Value "bind-address=127.0.0.1`r`ncharacter-set-server=utf8mb4`r`ncollation-server=utf8mb4_general_ci"
}
Start-DevDb

# ---------- Database ----------
$exists = (& $MariaExe @(Get-DbArgs) -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DbName'") -eq '1'
if ($Reseed -and $exists) {
    Write-Step "Dropping $DbName (-Reseed)"
    & $MariaExe @(Get-DbArgs) -e "DROP DATABASE ``$DbName``"
    $exists = $false
}
if (-not $exists) {
    Write-Step "Creating database $DbName"
    $schema = (Get-Content (Join-Path $RepoRoot 'deploy\database\timeclock-schema.sql') -Raw) -replace 'DB_NAME_PLACEHOLDER', $DbName
    $tmpSql = Join-Path $DevDir 'schema.tmp.sql'
    [IO.File]::WriteAllText($tmpSql, $schema, (New-Object Text.UTF8Encoding $false))
    & $MariaExe @(Get-DbArgs) -e "source $($tmpSql -replace '\\','/')"
    Remove-Item $tmpSql
    # The schema already includes every migration: record them as applied
    $values = (Get-ChildItem (Join-Path $RepoRoot 'deploy\database\migrations\*.sql') | ForEach-Object { "('$($_.Name)')" }) -join ','
    & $MariaExe @(Get-DbArgs) $DbName -e "INSERT IGNORE INTO schema_migrations (filename) VALUES $values"
    & $MariaExe @(Get-DbArgs) -e "CREATE USER IF NOT EXISTS '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPass'; CREATE USER IF NOT EXISTS '$DbUser'@'localhost' IDENTIFIED BY '$DbPass'; GRANT ALL ON ``$DbName``.* TO '$DbUser'@'127.0.0.1'; GRANT ALL ON ``$DbName``.* TO '$DbUser'@'localhost'; FLUSH PRIVILEGES;"
}

# Any migrations newer than the database (e.g. after a git pull)
Set-DevEnv
& $PhpExe -c $PhpIni (Join-Path $RepoRoot 'deploy\scripts\run_migrations.php') (Join-Path $RepoRoot 'deploy\database\migrations') | Out-Host
if ($LASTEXITCODE -ne 0) { throw "Migrations failed" }

# ---------- Composer packages ----------
if (-not (Test-Path (Join-Path $RepoRoot 'app\vendor\autoload.php'))) {
    Write-Step "Installing PHP packages (composer install)"
    & $PhpExe -c $PhpIni $ComposerPhar install --working-dir (Join-Path $RepoRoot 'app') --no-interaction --no-dev | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "composer install failed" }
}

# ---------- Demo data ----------
if (-not $exists) {
    Write-Step "Seeding demo data"
    & $PhpExe -c $PhpIni (Join-Path $RepoRoot 'deploy\scripts\seed_demo.php') | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "Demo seed failed" }
}

Write-Host ""
Write-Host "Setup complete. Start the app with:" -ForegroundColor Green
Write-Host "  powershell -ExecutionPolicy Bypass -File deploy\dev\start.ps1"
