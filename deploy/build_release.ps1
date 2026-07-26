param(
    [string]$OutputDirectory = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($OutputDirectory -eq '') {
    $OutputDirectory = Join-Path (Split-Path $projectRoot -Parent) '1.7 PRO releases'
}
$OutputDirectory = [IO.Path]::GetFullPath($OutputDirectory)

$versionSource = [IO.File]::ReadAllText((Join-Path $projectRoot 'includes\version.php'))
$match = [regex]::Match($versionSource, "define\('APP_VERSION',\s*'([^']+)'\)")
if (-not $match.Success) { throw 'Unable to read APP_VERSION.' }
$version = $match.Groups[1].Value

$stage = Join-Path ([IO.Path]::GetTempPath()) ('super-ma-release-' + [guid]::NewGuid().ToString('N'))
$archive = Join-Path $OutputDirectory ("Super-Ma-Agents-v$version.zip")
$excludedRoots = @('.git', '.idea', '.vscode', 'tests', 'docs', 'install.lock', 'login_test_probe.php', 'login_test_router.php')
$archiveExtensions = @('.zip', '.7z', '.rar', '.tar', '.gz', '.tgz')

function Remove-SafeStage([string]$Path) {
    $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    $resolved = [IO.Path]::GetFullPath($Path)
    $leaf = Split-Path $resolved -Leaf
    if ($resolved.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -and
        $leaf.StartsWith('super-ma-release-') -and
        (Test-Path -LiteralPath $resolved)) {
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}

New-Item -ItemType Directory -Path $stage -Force | Out-Null
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null

foreach ($item in @(Get-ChildItem -LiteralPath $projectRoot -Force)) {
    $isExcluded = $excludedRoots -contains $item.Name
    $isArchive = -not $item.PSIsContainer -and $archiveExtensions -contains $item.Extension.ToLowerInvariant()
    if (-not $isExcluded -and -not $isArchive) {
        Copy-Item -LiteralPath $item.FullName -Destination $stage -Recurse -Force
    }
}

# Keep directory protection files only; never package user works, logs, locks, cache, or progress data.
$stageStorage = Join-Path $stage 'storage'
if (Test-Path -LiteralPath $stageStorage) {
    Get-ChildItem -LiteralPath $stageStorage -Recurse -File |
        Where-Object { $_.Name -ne '.htaccess' } |
        Remove-Item -Force
}
$stagedBuilder = Join-Path $stage 'deploy\build_release.ps1'
if (Test-Path -LiteralPath $stagedBuilder) { Remove-Item -LiteralPath $stagedBuilder -Force }

# Sanitize secrets even when the build runs from an installed instance.
$stageConfig = Join-Path $stage 'config.php'
$config = [IO.File]::ReadAllText($stageConfig)
$config = [regex]::Replace($config, "define\('DB_PASS',\s*'[^']*'\);", "define('DB_PASS',    '');")
$config = [regex]::Replace($config, "define\('ADMIN_USER',\s*'[^']*'\);", "define('ADMIN_USER', 'admin');")
$config = [regex]::Replace($config, "define\('ADMIN_PASS',\s*'[^']*'\);", "define('ADMIN_PASS', '!INSTALL_REQUIRED!');")
[IO.File]::WriteAllText($stageConfig, $config, (New-Object Text.UTF8Encoding($false)))

if (Test-Path -LiteralPath $archive) { Remove-Item -LiteralPath $archive -Force }
Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $archive -CompressionLevel Optimal

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::OpenRead($archive)
$forbidden = @($zip.Entries | Where-Object {
    $normalized = $_.FullName.Replace('\\', '/')
    $normalized -match '(^|/)(install\.lock|login_test_(probe|router)\.php)$' -or
    $normalized -match '(^|/)tests/' -or
    (($normalized -match '(^|/)storage/(write_progress|author_works)/') -and
        -not $normalized.EndsWith('/.htaccess'))
})
$zip.Dispose()

if ($forbidden.Count -gt 0) {
    Remove-Item -LiteralPath $archive -Force
    Remove-SafeStage $stage
    throw ('Release contains forbidden files: ' + (($forbidden.FullName) -join ', '))
}

Remove-SafeStage $stage
Write-Output $archive
