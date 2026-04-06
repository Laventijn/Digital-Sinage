param(
    [string]$Message = "backup: $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
)

$ErrorActionPreference = "Stop"

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw "Git is not installed or not available in PATH."
}

$changes = git status --porcelain
if (-not $changes) {
    Write-Host "No changes to backup."
    exit 0
}

git add -A

$commitOutput = & git commit -m $Message 2>&1
if ($LASTEXITCODE -ne 0) {
    if ($commitOutput -match "nothing to commit") {
        Write-Host "Nothing to commit."
        exit 0
    }

    Write-Host $commitOutput
    throw "Commit failed."
}

Write-Host $commitOutput

& git push origin HEAD
if ($LASTEXITCODE -ne 0) {
    throw "Push failed. Check your remote access and branch permissions."
}

Write-Host "Backup pushed to GitHub."
