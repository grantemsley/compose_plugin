#Requires -Version 7.0
<#
.SYNOPSIS
    Creates a GitHub release for the compose.manager plugin.

.DESCRIPTION
    This script bumps the version, builds the package, updates the .plg file with the
    correct MD5 hash, commits the changes, and creates a GitHub release with
    the package attached. If any step fails, changes are rolled back.

.PARAMETER BumpType
    The type of version bump: 'patch', 'minor', or 'major'.
    - patch: 0.1.0 -> 0.1.1
    - minor: 0.1.0 -> 0.2.0
    - major: 0.1.0 -> 1.0.0

.PARAMETER SkipBuild
    Skip the build step and use existing package in archive folder.

.PARAMETER DryRun
    Show what would be done without making any changes.

.PARAMETER NoPush
    Build and update files but don't push to GitHub or create release.

.EXAMPLE
    ./release.ps1 patch
    ./release.ps1 minor
    ./release.ps1 major
    ./release.ps1 patch -DryRun
    ./release.ps1 patch -SkipBuild
#>

param(
    [Parameter(Position=0)]
    [ValidateSet('patch', 'minor', 'major')]
    [string]$BumpType = 'patch',
    [switch]$SkipBuild,
    [switch]$DryRun,
    [switch]$NoPush
)

$ErrorActionPreference = "Stop"
$ScriptDir = $PSScriptRoot
$PlgPath = "$ScriptDir\compose.manager.plg"
$GitHubRepo = "mstrhakr/compose_plugin"

# Function to parse semver
function Parse-SemVer {
    param([string]$Version)
    if ($Version -match '^(\d+)\.(\d+)\.(\d+)(.*)$') {
        return @{
            Major = [int]$Matches[1]
            Minor = [int]$Matches[2]
            Patch = [int]$Matches[3]
            Suffix = $Matches[4]
        }
    }
    throw "Invalid semver format: $Version"
}

# Function to bump version
function Bump-Version {
    param(
        [string]$Version,
        [string]$BumpType
    )
    $semver = Parse-SemVer $Version
    switch ($BumpType) {
        'major' {
            $semver.Major++
            $semver.Minor = 0
            $semver.Patch = 0
        }
        'minor' {
            $semver.Minor++
            $semver.Patch = 0
        }
        'patch' {
            $semver.Patch++
        }
    }
    return "$($semver.Major).$($semver.Minor).$($semver.Patch)"
}

# Function to get current version from .plg file
function Get-CurrentVersion {
    $plgContent = Get-Content $PlgPath -Raw
    if ($plgContent -match 'ENTITY version\s+"([^"]+)"') {
        return $Matches[1]
    }
    throw "Could not determine version from .plg file"
}

# Function to check for uncommitted changes
function Test-UncommittedChanges {
    $status = git status --porcelain
    return -not [string]::IsNullOrWhiteSpace($status)
}

# Function to restore .plg file from git
function Restore-PlgFile {
    Write-Host "  Rolling back .plg file changes..." -ForegroundColor Yellow
    git checkout -- $PlgPath
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Compose Manager Release Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 0: Check for uncommitted changes
Write-Host "Checking for uncommitted changes..." -ForegroundColor Yellow

if ($DryRun) {
    Write-Host "  [DRY RUN] Would check for uncommitted changes" -ForegroundColor Gray
} else {
    if (Test-UncommittedChanges) {
        Write-Host ""
        Write-Host "ERROR: You have uncommitted changes!" -ForegroundColor Red
        Write-Host "Please commit or stash your changes before releasing." -ForegroundColor Red
        Write-Host ""
        Write-Host "Uncommitted files:" -ForegroundColor Yellow
        git status --short
        Write-Host ""
        exit 1
    }
    Write-Host "  No uncommitted changes found" -ForegroundColor Green
}

# Get current and new version
$CurrentVersion = Get-CurrentVersion
$NewVersion = Bump-Version -Version $CurrentVersion -BumpType $BumpType

Write-Host ""
Write-Host "Version bump: $CurrentVersion -> $NewVersion ($BumpType)" -ForegroundColor Cyan
Write-Host ""

$PackageName = "compose.manager-package-$NewVersion.txz"
$PackagePath = Join-Path "$ScriptDir\archive" $PackageName

# Step 1: Update version in .plg file
Write-Host "Step 1: Updating version in .plg file..." -ForegroundColor Yellow

if ($DryRun) {
    Write-Host "  [DRY RUN] Would update version to $NewVersion" -ForegroundColor Gray
} else {
    $plgContent = Get-Content $PlgPath -Raw
    $plgContent = $plgContent -replace '(ENTITY version\s+")[^"]+(")', "`${1}$NewVersion`${2}"
    Set-Content -Path $PlgPath -Value $plgContent -NoNewline
    Write-Host "  Updated version to $NewVersion" -ForegroundColor Green
}

# Step 2: Build package
if (-not $SkipBuild) {
    Write-Host "Step 2: Building package..." -ForegroundColor Yellow
    if ($DryRun) {
        Write-Host "  [DRY RUN] Would run: ./build.ps1 -Version $NewVersion" -ForegroundColor Gray
    } else {
        try {
            $buildResult = & "$ScriptDir\build.ps1" -Version $NewVersion
            if (-not $buildResult -or -not (Test-Path $PackagePath)) {
                throw "Build did not produce expected package"
            }
        } catch {
            Write-Host "  Build failed: $_" -ForegroundColor Red
            Restore-PlgFile
            throw "Build failed. Changes have been rolled back."
        }
    }
} else {
    Write-Host "Step 2: Skipping build (using existing package)" -ForegroundColor Yellow
    if (-not $DryRun -and -not (Test-Path $PackagePath)) {
        Restore-PlgFile
        throw "Package not found: $PackagePath. Changes have been rolled back."
    }
}

# Step 3: Calculate MD5 and update .plg file
Write-Host "Step 3: Updating MD5 in .plg file..." -ForegroundColor Yellow

if ($DryRun) {
    Write-Host "  [DRY RUN] Would calculate MD5 and update compose.manager.plg" -ForegroundColor Gray
    $md5 = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
} else {
    try {
        $md5 = (Get-FileHash -Path $PackagePath -Algorithm MD5).Hash.ToLower()
        Write-Host "  MD5: $md5" -ForegroundColor Cyan
        
        $plgContent = Get-Content $PlgPath -Raw
        $plgContent = $plgContent -replace '(ENTITY packageMD5\s+")[^"]+(")', "`${1}$md5`${2}"
        Set-Content -Path $PlgPath -Value $plgContent -NoNewline
        Write-Host "  Updated MD5 hash" -ForegroundColor Green
    } catch {
        Write-Host "  Failed to update MD5: $_" -ForegroundColor Red
        Restore-PlgFile
        throw "MD5 update failed. Changes have been rolled back."
    }
}

# Step 4: Git commit
Write-Host "Step 4: Committing changes..." -ForegroundColor Yellow

if ($DryRun) {
    Write-Host "  [DRY RUN] Would commit: Release v$NewVersion" -ForegroundColor Gray
} elseif (-not $NoPush) {
    try {
        git add $PlgPath
        git commit -m "Release v$NewVersion"
        Write-Host "  Committed changes" -ForegroundColor Green
    } catch {
        Write-Host "  Commit failed: $_" -ForegroundColor Red
        Restore-PlgFile
        throw "Commit failed. Changes have been rolled back."
    }
}

# Step 5: Push to GitHub
Write-Host "Step 5: Pushing to GitHub..." -ForegroundColor Yellow

if ($DryRun) {
    Write-Host "  [DRY RUN] Would push to origin" -ForegroundColor Gray
} elseif (-not $NoPush) {
    try {
        git push origin HEAD
        Write-Host "  Pushed to GitHub" -ForegroundColor Green
    } catch {
        Write-Host "  Push failed: $_" -ForegroundColor Red
        Write-Host "  Resetting last commit..." -ForegroundColor Yellow
        git reset --soft HEAD~1
        Restore-PlgFile
        throw "Push failed. Changes have been rolled back."
    }
}

# Step 6: Create GitHub release
Write-Host "Step 6: Creating GitHub release..." -ForegroundColor Yellow

# Get release notes from CHANGES section
$plgContent = Get-Content $PlgPath -Raw
$releaseNotes = ""
if ($plgContent -match "###$NewVersion\s*\n([\s\S]*?)(?=###|</CHANGES>)") {
    $releaseNotes = $Matches[1].Trim()
}

if (-not $releaseNotes) {
    $releaseNotes = "Release v$NewVersion"
}

if ($DryRun) {
    Write-Host "  [DRY RUN] Would create release v$NewVersion with notes:" -ForegroundColor Gray
    Write-Host "  $releaseNotes" -ForegroundColor Gray
} elseif (-not $NoPush) {
    $ghAvailable = Get-Command gh -ErrorAction SilentlyContinue
    
    if ($ghAvailable) {
        try {
            gh release create $NewVersion $PackagePath --repo $GitHubRepo --title "v$NewVersion" --notes $releaseNotes
            Write-Host "  Created GitHub release v$NewVersion" -ForegroundColor Green
        } catch {
            Write-Host "  WARNING: Failed to create GitHub release: $_" -ForegroundColor Yellow
            Write-Host "  The commit was pushed. Please create the release manually:" -ForegroundColor Yellow
            Write-Host "    https://github.com/$GitHubRepo/releases/new" -ForegroundColor Gray
        }
    } else {
        Write-Host "  GitHub CLI (gh) not found. Please create the release manually:" -ForegroundColor Yellow
        Write-Host "    1. Go to https://github.com/$GitHubRepo/releases/new" -ForegroundColor Gray
        Write-Host "    2. Tag: $NewVersion" -ForegroundColor Gray
        Write-Host "    3. Title: v$NewVersion" -ForegroundColor Gray
        Write-Host "    4. Upload: $PackagePath" -ForegroundColor Gray
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  Release complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Version: $NewVersion" -ForegroundColor Cyan
Write-Host "  Package: $PackagePath" -ForegroundColor Cyan
Write-Host "  MD5: $md5" -ForegroundColor Cyan
Write-Host ""

if ($NoPush) {
    Write-Host "NoPush flag set - changes not pushed to GitHub" -ForegroundColor Yellow
    Write-Host "To complete the release, run:" -ForegroundColor Yellow
    Write-Host "  git push origin HEAD" -ForegroundColor Gray
    Write-Host "  gh release create $NewVersion `"$PackagePath`" --repo $GitHubRepo --title `"v$NewVersion`"" -ForegroundColor Gray
}
