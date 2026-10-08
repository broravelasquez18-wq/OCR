param([string]$Php = 'C:\xampp\php\php.exe', [int]$Port = 8099)

$ErrorActionPreference = 'Stop'
$temporary = Join-Path $PSScriptRoot 'storage\tmp'
New-Item -ItemType Directory -Path $temporary -Force | Out-Null
$arguments = @(
    '-d', ('upload_tmp_dir="{0}"' -f $temporary),
    '-d', 'display_errors=0',
    '-d', 'display_startup_errors=0',
    '-d', 'log_errors=1',
    '-S', "localhost:$Port",
    '-t', 'public', 'public/index.php'
)
$server = Start-Process -FilePath $Php -ArgumentList $arguments -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru
Write-Output "Servidor iniciado: http://localhost:$Port (PID $($server.Id))"
