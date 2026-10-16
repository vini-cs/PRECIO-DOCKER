<#
 Precio - encerra o ambiente XAMPP:
   0. fecha o MySQL Workbench e REMOVE a conexão "Precio (XAMPP)" dele
   1. APAGA o banco precio_db (DROP DATABASE)
   2. desliga o MySQL (shutdown limpo; força só se precisar) e o Apache
   3. fecha o XAMPP Control Panel
 Pede administrador (UAC) sozinho: sem isso o Windows não deixa encerrar processos que
 subiram elevados (Apache/MySQL iniciados pelo painel).
 Opções: -KeepDatabase (não apaga o banco) | -KeepPanel (não fecha o painel)
         -KeepWorkbench (não fecha o Workbench nem remove a conexão) | -MysqlPassword X
#>
param(
    [string]$XamppPath = "",
    [string]$MysqlPassword = "",
    [string]$Database = "precio_db",
    [switch]$KeepDatabase,
    [switch]$KeepPanel,
    [switch]$KeepWorkbench,
    [string]$ConnectionName = "Precio (XAMPP)",
    [switch]$NoElevate,
    [switch]$NoPause,
    [switch]$Elevated,
    [string]$CallerAppData = ""   # uso interno: %APPDATA% do usuário que abriu o script
)

function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not $NoElevate -and -not $Elevated -and -not (Test-IsAdmin)) {
    Write-Host ""
    Write-Host "Pedindo permissão de administrador (necessária para encerrar Apache e MySQL)..." -ForegroundColor Yellow
    $argList = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", ('"' + $PSCommandPath + '"'), "-Elevated",
                 "-Database", ('"' + $Database + '"'), "-ConnectionName", ('"' + $ConnectionName + '"'),
                 "-CallerAppData", ('"' + $env:APPDATA + '"'))
    if ($XamppPath)     { $argList += @("-XamppPath", ('"' + $XamppPath + '"')) }
    if ($MysqlPassword) { $argList += @("-MysqlPassword", ('"' + $MysqlPassword + '"')) }
    if ($KeepDatabase)  { $argList += "-KeepDatabase" }
    if ($KeepPanel)     { $argList += "-KeepPanel" }
    if ($KeepWorkbench) { $argList += "-KeepWorkbench" }
    if ($NoPause)       { $argList += "-NoPause" }
    try {
        $proc = Start-Process -FilePath "powershell.exe" -ArgumentList $argList -Verb RunAs -Wait -PassThru
        exit $proc.ExitCode
    } catch {
        Write-Host "[!]  Permissão de administrador negada. Continuando sem ela (pode não conseguir encerrar tudo)." -ForegroundColor Yellow
    }
}

$ErrorActionPreference = "Stop"
. "$PSScriptRoot\lib-ports.ps1"
$Latin1 = [System.Text.Encoding]::GetEncoding(28591)
$AppData = if ($CallerAppData) { $CallerAppData } else { $env:APPDATA }

function Step($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor Cyan }
function Ok($m)   { Write-Host "    [ok] $m" -ForegroundColor Green }
function Warn($m) { Write-Host "    [!]  $m" -ForegroundColor Yellow }
function Bad($m)  { Write-Host "    [x]  $m" -ForegroundColor Red }
function Q([string]$s) { return '"' + $s + '"' }

function Finish([int]$code) {
    if (-not $NoPause) { Write-Host ""; Read-Host "Pressione Enter para fechar" | Out-Null }
    exit $code
}

function Invoke-Proc {
    param([string]$File, [string[]]$Arguments)
    $out = [System.IO.Path]::GetTempFileName()
    $err = [System.IO.Path]::GetTempFileName()
    $sp = @{ FilePath = $File; NoNewWindow = $true; Wait = $true; PassThru = $true
             RedirectStandardOutput = $out; RedirectStandardError = $err }
    if ($Arguments -and $Arguments.Count -gt 0) { $sp.ArgumentList = $Arguments }
    $p = Start-Process @sp
    $o = (Get-Content -Raw -Path $out -ErrorAction SilentlyContinue) + (Get-Content -Raw -Path $err -ErrorAction SilentlyContinue)
    Remove-Item $out, $err -Force -ErrorAction SilentlyContinue
    return [pscustomobject]@{ ExitCode = $p.ExitCode; Output = "$o" }
}

function Find-Xampp {
    $c = New-Object System.Collections.ArrayList
    if ($XamppPath)      { [void]$c.Add($XamppPath) }
    if ($env:XAMPP_HOME) { [void]$c.Add($env:XAMPP_HOME) }
    [void]$c.Add("C:\xampp")
    foreach ($d in (Get-PSDrive -PSProvider FileSystem)) { [void]$c.Add((Join-Path $d.Root "xampp")) }
    [void]$c.Add((Join-Path $env:ProgramFiles "xampp"))
    if (${env:ProgramFiles(x86)}) { [void]$c.Add((Join-Path ${env:ProgramFiles(x86)} "xampp")) }
    foreach ($p in $c) {
        if ($p -and (Test-Path (Join-Path $p "apache\bin\httpd.exe"))) { return (Resolve-Path $p).Path }
    }
    return $null
}

function Get-XamppProc([string]$xampp, [string]$name) {
    $r = @()
    foreach ($p in (Get-Process -Name $name -ErrorAction SilentlyContinue)) {
        $path = $null
        try { $path = $p.Path } catch {}
        if ($path -and $path.StartsWith($xampp, [System.StringComparison]::OrdinalIgnoreCase)) { $r += $p }
    }
    return $r
}

function Stop-Xampp([string]$xampp, [string]$name) {
    foreach ($p in @(Get-XamppProc $xampp $name)) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
}

# Remove do connections.xml do Workbench a(s) conexão(ões) com esse nome. Retorna quantas removeu.
# (o Workbench precisa estar fechado: ele regrava o arquivo ao sair)
function Remove-WorkbenchConnection([string]$name) {
    $cx = Join-Path $AppData "MySQL\Workbench\connections.xml"
    if (-not (Test-Path $cx)) { return 0 }
    $doc = New-Object System.Xml.XmlDocument
    $doc.PreserveWhitespace = $true
    $doc.Load($cx)
    $removed = 0
    foreach ($n in @($doc.SelectNodes("//value[@struct-name='db.mgmt.Connection']"))) {
        $nm = $n.SelectSingleNode("value[@key='name']")
        if ($nm -and $nm.InnerText -eq $name) { [void]$n.ParentNode.RemoveChild($n); $removed++ }
    }
    if ($removed -gt 0) {
        $bak = "$cx.precio.bak"
        if (-not (Test-Path $bak)) { Copy-Item $cx $bak }
        $doc.Save($cx)
    }
    return $removed
}

function Wait-Until([scriptblock]$cond, [int]$seconds) {
    $end = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $end) {
        if (& $cond) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return [bool](& $cond)
}

$failed = $false
try {
    Write-Host ""
    Write-Host "  PRECIO - encerrar ambiente XAMPP" -ForegroundColor White
    if (-not (Test-IsAdmin)) { Warn "Sem administrador: se algo não encerrar, rode de novo e aceite o UAC." }

    Step "Localizando o XAMPP"
    $xampp = Find-Xampp
    if (-not $xampp) { throw "XAMPP não encontrado. Rode: xampp-stop.ps1 -XamppPath D:\xampp" }
    Ok $xampp

    $mysqlExe  = Join-Path $xampp "mysql\bin\mysql.exe"
    $mysqldExe = Join-Path $xampp "mysql\bin\mysqld.exe"
    $adminExe  = Join-Path $xampp "mysql\bin\mysqladmin.exe"
    $myIni     = Join-Path $xampp "mysql\bin\my.ini"

    $port = 3306
    if (Test-Path $myIni) {
        $m = [regex]::Match([System.IO.File]::ReadAllText($myIni, $Latin1), '(?im)^[ \t]*port[ \t]*=[ \t]*(\d+)')
        if ($m.Success) { $port = [int]$m.Groups[1].Value }
    }
    $cli = @("-u", "root", "-h", "127.0.0.1", "-P", "$port", "--protocol=tcp")
    if ($MysqlPassword) { $cli += "-p$MysqlPassword" }

    # ------------------------------------------------------------------ 0
    if ($KeepWorkbench) {
        Step "MySQL Workbench"
        Warn "Workbench e conexão mantidos (-KeepWorkbench)."
    } else {
        Step "Fechando o MySQL Workbench"
        $wbOpen = @(Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue)
        $wbClosed = $true
        if ($wbOpen.Count -eq 0) {
            Ok "O Workbench não estava aberto"
        } else {
            foreach ($wp in $wbOpen) { try { [void]$wp.CloseMainWindow() } catch {} }      # tenta fechar normalmente
            if (-not (Wait-Until { -not (Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue) } 8)) {
                Warn "O Workbench não fechou sozinho; encerrando à força..."
                foreach ($wp in @(Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue)) { Stop-Process -Id $wp.Id -Force -ErrorAction SilentlyContinue }
                [void](Wait-Until { -not (Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue) } 8)
            }
            if (Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue) {
                Bad "O Workbench continua aberto; a conexão não foi removida."
                $wbClosed = $false
                $failed = $true
            } else {
                Ok "Workbench fechado"
            }
        }

        if ($wbClosed) {
            Step "Removendo a conexão '$ConnectionName' do Workbench"
            try {
                $n = Remove-WorkbenchConnection $ConnectionName
                if ($n -gt 0) { Ok "Conexão '$ConnectionName' removida" }
                else { Ok "A conexão '$ConnectionName' não existia (nada a remover)" }
            } catch {
                Bad "Não consegui remover a conexão: $($_.Exception.Message)"
                $failed = $true
            }
        }
    }

    # ------------------------------------------------------------------ 1
    Step "Apagando o banco $Database"
    if ($KeepDatabase) {
        Warn "Banco mantido (-KeepDatabase)."
    } else {
        $startedHere = $false
        if (-not (Test-PortOpen $port)) {
            if (Test-Path $mysqldExe) {
                Warn "MySQL estava parado; ligando só para apagar o banco..."
                Start-Process -FilePath $mysqldExe -WorkingDirectory $xampp -WindowStyle Hidden `
                    -ArgumentList @("--defaults-file=" + (Q $myIni), "--standalone")
                $startedHere = $true
                [void](Wait-Until { Test-PortOpen $port } 40)
            }
        }
        if (-not (Test-PortOpen $port)) {
            Bad "MySQL não respondeu na porta $port; o banco NÃO foi apagado. Veja $xampp\mysql\data\mysql_error.log"
            $failed = $true
        } else {
            $drop = Invoke-Proc -File $mysqlExe -Arguments ($cli + @("-e", (Q "DROP DATABASE IF EXISTS $Database")))
            $chk  = Invoke-Proc -File $mysqlExe -Arguments ($cli + @("-N", "-B", "-e", (Q "SHOW DATABASES LIKE '$Database'")))
            if ($drop.ExitCode -eq 0 -and $chk.ExitCode -eq 0 -and -not $chk.Output.Trim()) {
                Ok "Banco $Database apagado"
            } else {
                Bad "Não consegui apagar o banco: $($drop.Output.Trim()) $($chk.Output.Trim())"
                Bad "Se o root do MySQL tem senha, rode com -MysqlPassword SUASENHA."
                $failed = $true
            }
        }
    }

    # ------------------------------------------------------------------ 2
    Step "Desligando o MySQL"
    if (@(Get-XamppProc $xampp "mysqld").Count -eq 0) {
        Ok "MySQL já estava parado"
    } else {
        if ((Test-Path $adminExe) -and (Test-PortOpen $port)) {
            [void](Invoke-Proc -File $adminExe -Arguments ($cli + @("shutdown")))   # desligamento limpo
        }
        if (-not (Wait-Until { @(Get-XamppProc $xampp "mysqld").Count -eq 0 } 25)) {
            Warn "O MySQL não desligou sozinho; encerrando à força..."
            Stop-Xampp $xampp "mysqld"
            [void](Wait-Until { @(Get-XamppProc $xampp "mysqld").Count -eq 0 } 8)
        }
        if (@(Get-XamppProc $xampp "mysqld").Count -eq 0) { Ok "MySQL parado" }
        else { Bad "O MySQL continua rodando (precisa de administrador)."; $failed = $true }
    }

    Step "Desligando o Apache"
    if (@(Get-XamppProc $xampp "httpd").Count -eq 0) {
        Ok "Apache já estava parado"
    } else {
        Stop-Xampp $xampp "httpd"
        if (-not (Wait-Until { @(Get-XamppProc $xampp "httpd").Count -eq 0 } 10)) {
            Stop-Xampp $xampp "httpd"
            [void](Wait-Until { @(Get-XamppProc $xampp "httpd").Count -eq 0 } 5)
        }
        if (@(Get-XamppProc $xampp "httpd").Count -eq 0) { Ok "Apache parado" }
        else { Bad "O Apache continua rodando (precisa de administrador)."; $failed = $true }
    }

    # ------------------------------------------------------------------ 3
    if (-not $KeepPanel) {
        Step "Fechando o XAMPP Control Panel"
        $panels = @(Get-Process -Name "xampp-control" -ErrorAction SilentlyContinue)
        if ($panels.Count -eq 0) {
            Ok "O painel não estava aberto"
        } else {
            foreach ($pp in $panels) { Stop-Process -Id $pp.Id -Force -ErrorAction SilentlyContinue }
            if (Wait-Until { -not (Get-Process -Name "xampp-control" -ErrorAction SilentlyContinue) } 6) { Ok "Painel fechado" }
            else { Warn "Não consegui fechar o painel (feche-o manualmente)." }
        }
    }

    Write-Host ""
    if ($failed) {
        Write-Host "============================================================" -ForegroundColor Red
        Write-Host "  Terminou COM PROBLEMAS - veja as linhas [x] acima" -ForegroundColor Red
        Write-Host "============================================================" -ForegroundColor Red
        Finish 1
    }
    Write-Host "============================================================" -ForegroundColor Green
    Write-Host "  Ambiente encerrado" -ForegroundColor Green
    if (-not $KeepDatabase) { Write-Host "  Banco $Database apagado (start_dev.bat recria do zero)" -ForegroundColor Green }
    if (-not $KeepWorkbench) { Write-Host "  Workbench fechado e conexão '$ConnectionName' removida" -ForegroundColor Green }
    Write-Host "============================================================" -ForegroundColor Green
    Finish 0
}
catch {
    Write-Host ""
    Write-Host "[ERRO] $($_.Exception.Message)" -ForegroundColor Red
    Finish 1
}
