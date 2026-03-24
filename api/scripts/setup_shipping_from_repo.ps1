[CmdletBinding()]
param(
    [string]$Database = 'boomeritems',
    [string]$User = 'root',
    [string]$Password = ''
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$dumpSqlPath = Join-Path $projectRoot 'il-ilce-mahalle-sokak-veritabani-main\sql\titlecase_data.sql'
$zipPath = Join-Path $projectRoot 'il-ilce-mahalle-sokak-veritabani-main\sql\titlecase_data.zip'
$extractDir = Join-Path $projectRoot 'tmp_shipping_import'
$dumpPath = Join-Path $extractDir 'titlecase_data.sql'
$transformSql = Join-Path $projectRoot 'api\sql\import_nvi_locations_from_main_repo.sql'
$mysqlExe = 'C:\xampp\mysql\bin\mysql.exe'

function Invoke-MySqlFile {
    param(
        [Parameter(Mandatory = $true)][string]$SqlFile,
        [string]$DatabaseName = ''
    )

    if (-not (Test-Path $SqlFile)) {
        throw "SQL file not found: $SqlFile"
    }

    $passwordPart = if ($Password -ne '') { "-p$Password" } else { '' }
    $databasePart = if ($DatabaseName -ne '') { " $DatabaseName" } else { '' }
    $command = "`"$mysqlExe`" --default-character-set=utf8mb4 -u $User $passwordPart$databasePart < `"$SqlFile`""

    cmd.exe /c $command
    if ($LASTEXITCODE -ne 0) {
        throw "mysql exited with code $LASTEXITCODE while running $SqlFile"
    }
}

if (-not (Test-Path $mysqlExe)) {
    throw "mysql.exe not found: $mysqlExe"
}

if (Test-Path $dumpSqlPath) {
    $rawDumpToImport = $dumpSqlPath
} elseif (Test-Path $zipPath) {
    New-Item -ItemType Directory -Force -Path $extractDir | Out-Null
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    if (Test-Path $dumpPath) {
        Remove-Item $dumpPath -Force
    }
    [System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $extractDir)

    if (-not (Test-Path $dumpPath)) {
        throw "Extracted SQL dump not found: $dumpPath"
    }

    $rawDumpToImport = $dumpPath
} else {
    throw "Location dump not found: $dumpSqlPath"
}

Write-Host 'Importing source dump into boomeritems...'
Write-Host "Importing source dump into $Database..."
Invoke-MySqlFile -SqlFile $rawDumpToImport -DatabaseName $Database

Write-Host 'Mapping source tables into shipping tables...'
Invoke-MySqlFile -SqlFile $transformSql -DatabaseName $Database

Write-Host 'Shipping locations import completed.'
