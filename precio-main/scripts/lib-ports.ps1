# Funções de porta compartilhadas (XAMPP e Docker)

function Test-PortFree {
    param([int]$Port)

    # 1) alguém já está escutando nessa porta (qualquer endereço)?
    try {
        $busy = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction Stop
        if ($busy) { return $false }
    } catch {
        # nenhuma conexão encontrada (ou cmdlet indisponível): segue para o teste de bind
    }

    # 2) consegue abrir a porta de verdade? (também detecta portas reservadas pelo Windows/Hyper-V)
    foreach ($ip in @([System.Net.IPAddress]::Any, [System.Net.IPAddress]::Loopback)) {
        $listener = $null
        try {
            $listener = New-Object System.Net.Sockets.TcpListener($ip, $Port)
            $listener.ExclusiveAddressUse = $true
            $listener.Start()
        } catch {
            return $false
        } finally {
            if ($listener) { try { $listener.Stop() } catch {} }
        }
    }
    return $true
}

function Find-FreePort {
    param([int]$Start, [int]$Max = 300)
    for ($p = $Start; $p -lt ($Start + $Max); $p++) {
        if (Test-PortFree -Port $p) { return $p }
    }
    throw "Nenhuma porta livre encontrada a partir de $Start."
}

function Test-PortOpen {
    # true se algo ACEITA conexão em 127.0.0.1:Port (serviço de pé)
    param([int]$Port, [int]$TimeoutMs = 600)
    $c = New-Object System.Net.Sockets.TcpClient
    try {
        $ar = $c.BeginConnect("127.0.0.1", $Port, $null, $null)
        if ($ar.AsyncWaitHandle.WaitOne($TimeoutMs) -and $c.Connected) { return $true }
        return $false
    } catch { return $false } finally { $c.Close() }
}
