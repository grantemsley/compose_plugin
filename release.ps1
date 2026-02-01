#Requires -Version 7.0
<#
.SYNOPSIS
    Creates a release tag for the compose.manager plugin.

.DESCRIPTION
    This script creates and pushes a version tag which triggers GitHub Actions
    to build the package and create a release. Uses date-based versioning (YYYY.MM.DD).
    
    Multiple releases on the same day get suffixes: v2026.02.01, v2026.02.01a, v2026.02.01b

.PARAMETER DryRun
    Show what would be done without making any changes.

.PARAMETER Force
    Skip all confirmation prompts.

.EXAMPLE
    ./release.ps1           # Creates v2026.02.01 (or next available)
    ./release.ps1 -DryRun   # Preview without changes
    ./release.ps1 -Force    # Skip confirmations
#>

param(
    [switch]$DryRun,
    [switch]$Force
)

$ErrorActionPreference = "Stop"
$GitHubRepo = "mstrhakr/compose_plugin"

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Compose Manager Release Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Get today's date in version format
$dateVersion = Get-Date -Format "yyyy.MM.dd"
$baseTag = "v$dateVersion"

# Fetch latest tags from remote
Write-Host "Fetching latest from origin..." -ForegroundColor Yellow
git fetch origin --tags

# Get existing tags for today
$existingTags = git tag -l "$baseTag*" 2>$null | Sort-Object

if ($existingTags) {
    Write-Host "Existing tags for today:" -ForegroundColor Yellow
    $existingTags | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
    
    # Find the next suffix
    $lastTag = $existingTags | Select-Object -Last 1
    
    if ($lastTag -eq $baseTag) {
        # First release was without suffix, next is 'a'
        $newTag = "${baseTag}a"
    } elseif ($lastTag -match "^v\d{4}\.\d{2}\.\d{2}([a-z])$") {
        # Increment the suffix letter
        $lastSuffix = $matches[1]
        $nextSuffix = [char]([int][char]$lastSuffix + 1)
        if ($nextSuffix -gt 'z') {
            Write-Error "Too many releases today! (exceeded 'z' suffix)"
            exit 1
        }
        $newTag = "$baseTag$nextSuffix"
    } else {
        Write-Error "Unexpected tag format: $lastTag"
        exit 1
    }
} else {
    # No releases today yet - use base tag without suffix
    $newTag = $baseTag
}

Write-Host ""
Write-Host "New release tag: " -NoNewline
Write-Host $newTag -ForegroundColor Green
Write-Host ""

# Check for uncommitted changes
$status = git status --porcelain
if ($status) {
    Write-Host "Warning: You have uncommitted changes:" -ForegroundColor Yellow
    $status | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
    Write-Host ""
    
    if (-not $Force) {
        $response = Read-Host "Continue anyway? (y/N)"
        if ($response -ne 'y' -and $response -ne 'Y') {
            Write-Host "Aborted." -ForegroundColor Red
            exit 1
        }
    }
}

# Check if we're on main branch
$currentBranch = git branch --show-current
if ($currentBranch -ne 'main') {
    Write-Host "Warning: You're on branch '$currentBranch', not 'main'" -ForegroundColor Yellow
    
    if (-not $Force) {
        $response = Read-Host "Continue anyway? (y/N)"
        if ($response -ne 'y' -and $response -ne 'Y') {
            Write-Host "Aborted." -ForegroundColor Red
            exit 1
        }
    }
}

# Check if local is behind remote
$behind = git rev-list --count "HEAD..origin/$currentBranch" 2>$null
if ($behind -gt 0) {
    Write-Host "Warning: Local branch is $behind commit(s) behind origin/$currentBranch" -ForegroundColor Yellow
    
    if (-not $Force) {
        $response = Read-Host "Pull changes first? (Y/n)"
        if ($response -ne 'n' -and $response -ne 'N') {
            git pull origin $currentBranch
        }
    }
}

if ($DryRun) {
    Write-Host ""
    Write-Host "[DRY RUN] Would execute:" -ForegroundColor Magenta
    Write-Host "  git tag $newTag" -ForegroundColor Gray
    Write-Host "  git push origin $newTag" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Run without -DryRun to create the release." -ForegroundColor Cyan
    exit 0
}

# Confirm release
if (-not $Force) {
    Write-Host ""
    $response = Read-Host "Create and push tag '$newTag'? (y/N)"
    if ($response -ne 'y' -and $response -ne 'Y') {
        Write-Host "Aborted." -ForegroundColor Red
        exit 1
    }
}

# Push any pending commits first
Write-Host ""
Write-Host "Pushing commits to origin/$currentBranch..." -ForegroundColor Cyan
git push origin $currentBranch

# Create and push the tag
Write-Host ""
Write-Host "Creating tag $newTag..." -ForegroundColor Cyan
git tag $newTag

Write-Host "Pushing tag to origin..." -ForegroundColor Cyan
git push origin $newTag

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  Release $newTag initiated!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "GitHub Actions will now:" -ForegroundColor Cyan
Write-Host "  1. Build the TXZ package" -ForegroundColor Gray
Write-Host "  2. Calculate MD5 hash" -ForegroundColor Gray
Write-Host "  3. Create GitHub Release" -ForegroundColor Gray
Write-Host "  4. Update PLG in dev branch" -ForegroundColor Gray
Write-Host ""
Write-Host "Monitor progress at:" -ForegroundColor Cyan
Write-Host "  https://github.com/$GitHubRepo/actions" -ForegroundColor Blue
Write-Host ""
Write-Host "Release will be available at:" -ForegroundColor Cyan
Write-Host "  https://github.com/$GitHubRepo/releases/tag/$newTag" -ForegroundColor Blue
Write-Host ""
