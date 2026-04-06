param(
    [Parameter(Mandatory = $true)]
    [string]$HostName,

    [string]$User = "pi",
    [string]$ConfigAlias = "raspberrypi-kiosk",
    [string]$KeyPath = "$HOME/.ssh/id_ed25519_pi"
)

$ErrorActionPreference = "Stop"

function Ensure-Command {
    param([string]$Name)
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Command '$Name' is not available. Install it first."
    }
}

Ensure-Command ssh
Ensure-Command ssh-keygen

$sshDir = Join-Path $HOME ".ssh"
if (-not (Test-Path $sshDir)) {
    New-Item -ItemType Directory -Path $sshDir | Out-Null
}

if (-not (Test-Path $KeyPath)) {
    Write-Host "Creating SSH key at $KeyPath ..."
    # In Windows PowerShell 5.1, empty native args can be dropped; use cmd.exe for -N "".
    $comment = "$env:USERNAME@$env:COMPUTERNAME-pi"
    $cmdLine = 'ssh-keygen -t ed25519 -f "' + $KeyPath + '" -C "' + $comment + '" -N ""'
    cmd /c $cmdLine
}
else {
    Write-Host "SSH key already exists: $KeyPath"
}

$configPath = Join-Path $sshDir "config"
if (-not (Test-Path $configPath)) {
    New-Item -ItemType File -Path $configPath | Out-Null
}

$configContent = Get-Content $configPath -Raw
$aliasPattern = "(?m)^Host\s+$([regex]::Escape($ConfigAlias))\s*$"
if ([string]::IsNullOrWhiteSpace($configContent) -or ($configContent -notmatch $aliasPattern)) {
    Add-Content -Path $configPath -Value @"

Host $ConfigAlias
    HostName $HostName
    User $User
    IdentityFile $KeyPath
    ServerAliveInterval 30
    ServerAliveCountMax 4
"@
    Write-Host "Added SSH config entry: $ConfigAlias"
}
else {
    Write-Host "SSH config entry already exists: $ConfigAlias"
}

$pubKey = Get-Content "$KeyPath.pub" -Raw
Write-Host "Copying public key to $User@$HostName ..."
$pubKey | ssh "$User@$HostName" "umask 077; mkdir -p ~/.ssh; cat >> ~/.ssh/authorized_keys"
if ($LASTEXITCODE -ne 0) {
    throw "Failed to copy public key to $User@$HostName."
}

Write-Host "Testing key-based login via alias '$ConfigAlias' ..."
ssh -o BatchMode=yes $ConfigAlias "echo SSH_OK && hostname"
if ($LASTEXITCODE -ne 0) {
    throw "SSH test via alias '$ConfigAlias' failed. Check ~/.ssh/config and try: ssh -v $ConfigAlias"
}

Write-Host "Done. You can now run: code --remote ssh-remote+$ConfigAlias /home/$User/Digital-Sinage"
