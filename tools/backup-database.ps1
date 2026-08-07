<#
Creates a timestamped SQL backup of the configured POS database.

Run from the project root:
  powershell -ExecutionPolicy Bypass -File .\tools\backup-database.ps1

The script uses DB_HOST, DB_USER, DB_PASS, and DB_NAME when set. For local
XAMPP development it falls back to localhost, root, no password, and possystem_db.
#>
[CmdletBinding()]
param(
    [string]$OutputDirectory = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $PSScriptRoot '..\backups'
}

$dbHost = if ($env:DB_HOST) { $env:DB_HOST.Trim() } else { 'localhost' }
$dbUser = if ($env:DB_USER) { $env:DB_USER.Trim() } else { 'root' }
$dbPassword = if ($null -ne $env:DB_PASS) { $env:DB_PASS } else { '' }
$dbName = if ($env:DB_NAME) { $env:DB_NAME.Trim() } else { 'possystem_db' }

$dumpCandidates = @(
    'C:\xampp\mysql\bin\mysqldump.exe',
    'mysqldump.exe'
)
$dumpExe = $dumpCandidates | Where-Object { $_ -eq 'mysqldump.exe' -or (Test-Path -LiteralPath $_) } | Select-Object -First 1
if (-not $dumpExe) {
    throw 'mysqldump.exe was not found. Install MySQL client tools or update the script path.'
}

$resolvedOutput = [System.IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $resolvedOutput -Force | Out-Null

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupPath = Join-Path $resolvedOutput ("possystem-backup-$timestamp.sql")
$temporaryPath = "$backupPath.partial"

$databaseCandidates = @($dbName)
if (-not $env:DB_NAME -and $dbName -eq 'possystem_db') {
    # Match the legacy local fallback used by php/db-connection.php.
    $databaseCandidates += 'possytem_db'
}

$arguments = @(
    "--host=$dbHost",
    "--user=$dbUser",
    '--single-transaction',
    '--routines',
    '--events',
    '--triggers',
    '--add-drop-table',
    '--hex-blob',
    '--default-character-set=utf8mb4',
    "--result-file=$temporaryPath"
)
if ($dbPassword -ne '') {
    $arguments = @("--password=$dbPassword") + $arguments
}

try {
    $backupCreated = $false
    foreach ($candidateDatabase in $databaseCandidates) {
        if (Test-Path -LiteralPath $temporaryPath) {
            Remove-Item -LiteralPath $temporaryPath -Force
        }

        & $dumpExe @arguments $candidateDatabase 2>$null
        if ($LASTEXITCODE -eq 0 -and (Test-Path -LiteralPath $temporaryPath) -and (Get-Item -LiteralPath $temporaryPath).Length -gt 0) {
            $dbName = $candidateDatabase
            $backupCreated = $true
            break
        }
    }
    if (-not $backupCreated) {
        throw "mysqldump could not create a usable backup for: $($databaseCandidates -join ', ')."
    }

    Move-Item -LiteralPath $temporaryPath -Destination $backupPath
    $hash = (Get-FileHash -LiteralPath $backupPath -Algorithm SHA256).Hash
    Write-Host "Backup created: $backupPath"
    Write-Host "Database: $dbName"
    Write-Host "SHA-256: $hash"
    Write-Host 'Keep a copy outside this computer or server.'
} catch {
    if (Test-Path -LiteralPath $temporaryPath) {
        Remove-Item -LiteralPath $temporaryPath -Force
    }
    throw
}
