# Uso: powershell -File free-port.ps1 -Start 8080   -> imprime a primeira porta livre >= Start
param([int]$Start = 8080)
. "$PSScriptRoot\lib-ports.ps1"
Write-Output (Find-FreePort -Start $Start)
