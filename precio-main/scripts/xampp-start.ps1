<#
 Precio - sobe o ambiente XAMPP completo:
   1. localiza o XAMPP
   2. escolhe uma porta livre para o Apache (e SSL) e ajusta o httpd.conf  <- corrige "Apache não inicia"
   3. escolhe porta livre para o MySQL (3306 se livre)
   4. copia o projeto para htdocs\precio
   5. reinicia o XAMPP Control Panel com autostart de Apache + MySQL (e garante que os dois subam)
   6. importa o banco precio_db completo (back-end\setup.sql)
   7. abre o MySQL Workbench já com uma conexão para o banco
   8. abre o site no navegador

 O script pede permissão de administrador (UAC) sozinho: sem isso o Windows não deixa gravar o
 xampp-control.ini nem fechar/controlar um painel que já esteja aberto como administrador.
#>
param(
    [string]$XamppPath = "",
    [int]$PreferredApachePort = 80,
    [string]$MysqlPassword = "",
    [switch]$NoWorkbench,
    [switch]$NoBrowser,
    [switch]$NoElevate,           # não pedir administrador
    [switch]$NoPause,             # não esperar Enter no final
    [switch]$Elevated,            # uso interno: esta é a janela elevada
    [string]$CallerAppData = ""   # uso interno: %APPDATA% do usuário que abriu o script
)

function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# Relança o próprio script como administrador (uma janela nova, com UAC) e espera ela terminar
if (-not $NoElevate -and -not $Elevated -and -not (Test-IsAdmin)) {
    Write-Host ""
    Write-Host "Pedindo permissão de administrador (necessária para o XAMPP subir Apache e MySQL sozinho)..." -ForegroundColor Yellow
    $argList = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", ('"' + $PSCommandPath + '"'),
                 "-Elevated", "-CallerAppData", ('"' + $env:APPDATA + '"'), "-PreferredApachePort", "$PreferredApachePort")
    if ($XamppPath)     { $argList += @("-XamppPath", ('"' + $XamppPath + '"')) }
    if ($MysqlPassword) { $argList += @("-MysqlPassword", ('"' + $MysqlPassword + '"')) }
    if ($NoWorkbench)   { $argList += "-NoWorkbench" }
    if ($NoBrowser)     { $argList += "-NoBrowser" }
    if ($NoPause)       { $argList += "-NoPause" }
    try {
        $proc = Start-Process -FilePath "powershell.exe" -ArgumentList $argList -Verb RunAs -Wait -PassThru
        exit $proc.ExitCode
    } catch {
        Write-Host "[!]  Permissão de administrador negada. Continuando sem ela (o autostart do painel pode não funcionar)." -ForegroundColor Yellow
    }
}

$ErrorActionPreference = "Stop"
$AppData = if ($CallerAppData) { $CallerAppData } else { $env:APPDATA }
. "$PSScriptRoot\lib-ports.ps1"

$ProjectPath = Split-Path -Parent $PSScriptRoot
$Latin1 = [System.Text.Encoding]::GetEncoding(28591)   # preserva bytes dos arquivos de config

function Step($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor Cyan }
function Ok($m)   { Write-Host "    [ok] $m" -ForegroundColor Green }
function Warn($m) { Write-Host "    [!]  $m" -ForegroundColor Yellow }

function Q([string]$s) { return '"' + $s + '"' }

function Finish([int]$code) {
    if (-not $NoPause) { Write-Host ""; Read-Host "Pressione Enter para fechar" | Out-Null }
    exit $code
}

# Abre um programa SEM privilégios de administrador (navegador/Workbench não devem rodar elevados).
# Truque: o Explorer (que roda sem elevação) abre um atalho .lnk apontando para o programa.
function Start-Unelevated([string]$File, [string]$Arguments = "") {
    if (Test-IsAdmin) {
        $lnk = Join-Path $env:TEMP ("precio-" + [guid]::NewGuid().ToString("N") + ".lnk")
        try {
            $sc = (New-Object -ComObject WScript.Shell).CreateShortcut($lnk)
            $sc.TargetPath = $File
            $sc.Arguments = $Arguments
            $sc.WorkingDirectory = Split-Path -Parent $File
            $sc.Save()
            Start-Process -FilePath "explorer.exe" -ArgumentList (Q $lnk)
            Start-Sleep -Seconds 3
            Remove-Item $lnk -Force -ErrorAction SilentlyContinue
            return
        } catch {
            Remove-Item $lnk -Force -ErrorAction SilentlyContinue
        }
    }
    if ($Arguments) { Start-Process -FilePath $File -ArgumentList $Arguments } else { Start-Process -FilePath $File }
}

# Liga Apache=1 e MySQL=1 em [Autostart] do xampp-control.ini (cria o arquivo/seção se faltar)
function Set-PanelAutostart([string]$ini) {
    $lines = New-Object System.Collections.ArrayList
    if (Test-Path $ini) {
        $fi = Get-Item $ini
        if ($fi.IsReadOnly) { $fi.IsReadOnly = $false }
        foreach ($l in [System.IO.File]::ReadAllLines($ini, $Latin1)) { [void]$lines.Add($l) }
    }

    $hdr = -1
    for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i].Trim() -ieq "[Autostart]") { $hdr = $i; break } }
    if ($hdr -lt 0) { [void]$lines.Add("[Autostart]"); $hdr = $lines.Count - 1 }

    $end = $lines.Count
    for ($i = $hdr + 1; $i -lt $lines.Count; $i++) { if ($lines[$i].Trim() -match '^\[.*\]$') { $end = $i; break } }

    $seenA = $false; $seenM = $false
    for ($i = $hdr + 1; $i -lt $end; $i++) {
        $x = $lines[$i].Trim()
        if     ($x -match '^Apache\s*=') { $lines[$i] = "Apache=1"; $seenA = $true }
        elseif ($x -match '^MySQL\s*=')  { $lines[$i] = "MySQL=1";  $seenM = $true }
    }
    if (-not $seenM) { $lines.Insert($hdr + 1, "MySQL=1") }
    if (-not $seenA) { $lines.Insert($hdr + 1, "Apache=1") }

    [System.IO.File]::WriteAllLines($ini, [string[]]$lines.ToArray(), $Latin1)
}

function Invoke-Proc {
    param([string]$File, [string[]]$Arguments, [string]$StdinFile = "", [string]$WorkDir = "")
    $out = [System.IO.Path]::GetTempFileName()
    $err = [System.IO.Path]::GetTempFileName()
    $sp = @{ FilePath = $File; NoNewWindow = $true; Wait = $true; PassThru = $true
             RedirectStandardOutput = $out; RedirectStandardError = $err }
    if ($Arguments -and $Arguments.Count -gt 0) { $sp.ArgumentList = $Arguments }
    if ($StdinFile) { $sp.RedirectStandardInput = $StdinFile }
    if ($WorkDir)   { $sp.WorkingDirectory = $WorkDir }
    $p = Start-Process @sp
    $o = (Get-Content -Raw -Path $out -ErrorAction SilentlyContinue) + (Get-Content -Raw -Path $err -ErrorAction SilentlyContinue)
    Remove-Item $out, $err -Force -ErrorAction SilentlyContinue
    return [pscustomobject]@{ ExitCode = $p.ExitCode; Output = "$o" }
}

function Find-Xampp {
    $c = New-Object System.Collections.ArrayList
    if ($XamppPath)        { [void]$c.Add($XamppPath) }
    if ($env:XAMPP_HOME)   { [void]$c.Add($env:XAMPP_HOME) }
    [void]$c.Add("C:\xampp")
    foreach ($d in (Get-PSDrive -PSProvider FileSystem)) { [void]$c.Add((Join-Path $d.Root "xampp")) }
    [void]$c.Add((Join-Path $env:ProgramFiles "xampp"))
    if (${env:ProgramFiles(x86)}) { [void]$c.Add((Join-Path ${env:ProgramFiles(x86)} "xampp")) }
    foreach ($p in $c) {
        if ($p -and (Test-Path (Join-Path $p "apache\bin\httpd.exe"))) { return (Resolve-Path $p).Path }
    }
    return $null
}

function Read-Conf([string]$path)  { return [System.IO.File]::ReadAllText($path, $Latin1) }
function Write-Conf([string]$path, [string]$text) { [System.IO.File]::WriteAllText($path, $text, $Latin1) }
function Backup-Once([string]$path) {
    $bak = "$path.precio.bak"
    if (-not (Test-Path $bak)) { Copy-Item $path $bak }
}

function Stop-XamppProcess([string]$xampp, [string]$name) {
    foreach ($proc in (Get-Process -Name $name -ErrorAction SilentlyContinue)) {
        $path = $null
        try { $path = $proc.Path } catch {}
        if ($path -and $path.StartsWith($xampp, [System.StringComparison]::OrdinalIgnoreCase)) {
            Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
        }
    }
}

function Test-XamppProcessRunning([string]$xampp, [string]$name) {
    foreach ($proc in (Get-Process -Name $name -ErrorAction SilentlyContinue)) {
        $path = $null
        try { $path = $proc.Path } catch {}
        if ($path -and $path.StartsWith($xampp, [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    return $false
}

# Troca a 1ª diretiva ativa e comenta as repetidas
function Set-SingleDirective([string]$text, [string]$directive, [string]$value) {
    $script:__n = 0
    $pattern = '(?im)^(?![ \t]*#)[ \t]*' + $directive + '[ \t]+\S+[ \t]*(?=\r?$)'
    return [regex]::Replace($text, $pattern, {
        param($m)
        $script:__n++
        if ($script:__n -eq 1) { return "$directive $value" } else { return "#" + $m.Value }
    })
}

function Wait-Until([scriptblock]$cond, [int]$seconds) {
    $end = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $end) {
        if (& $cond) { return $true }
        Start-Sleep -Milliseconds 700
    }
    return [bool](& $cond)
}

function Find-Workbench {
    $roots = @($env:ProgramFiles, ${env:ProgramFiles(x86)}) | Where-Object { $_ }
    foreach ($r in $roots) {
        $mysqlDir = Join-Path $r "MySQL"
        if (-not (Test-Path $mysqlDir)) { continue }
        foreach ($d in (Get-ChildItem -Path $mysqlDir -Directory -Filter "MySQL Workbench*" -ErrorAction SilentlyContinue)) {
            $exe = Join-Path $d.FullName "MySQLWorkbench.exe"
            if (Test-Path $exe) { return $exe }
        }
    }
    return $null
}

# ---- MySQL Workbench: cria/atualiza a conexão em connections.xml -------------------------------
function Set-WbVal($doc, $parent, [string]$key, [string]$type, [string]$val) {
    $node = $parent.SelectSingleNode("value[@key='$key']")
    if (-not $node) {
        $node = $doc.CreateElement("value")
        $node.SetAttribute("type", $type)
        $node.SetAttribute("key", $key)
        [void]$parent.AppendChild($node)
    }
    $node.InnerText = $val
}

# Conexão TCP/IP nova no formato interno do Workbench (usada quando não há nenhuma para clonar)
function New-WbConnectionNode($doc) {
    $frag = $doc.CreateDocumentFragment()
    $frag.InnerXml = '<value type="object" struct-name="db.mgmt.Connection" id="' + [guid]::NewGuid().ToString() + '" key="">' +
        '<link type="object" struct-name="db.mgmt.Driver" key="driver">com.mysql.rdbms.mysql.driver.native</link>' +
        '<value type="string" key="hostIdentifier"></value>' +
        '<value type="int" key="isDefault">0</value>' +
        '<value type="dict" key="modules"/>' +
        '<value type="dict" key="parameterValues">' +
        '<value type="string" key="SQL_MODE"></value>' +
        '<value type="string" key="hostName"></value>' +
        '<value type="int" key="port"></value>' +
        '<value type="string" key="schema"></value>' +
        '<value type="string" key="userName"></value>' +
        '</value>' +
        '<value type="string" key="name"></value>' +
        '<value type="dict" key="settings"/>' +
        '</value>'
    return $frag.FirstChild
}

# Retorna $true se a conexão ficou gravada; senão $false e o motivo em $script:WbReason.
function Set-WorkbenchConnection([string]$name, [int]$port, [string]$schema, [string]$user) {
    $script:WbReason = ""
    $dir = Join-Path $AppData "MySQL\Workbench"
    $cx  = Join-Path $dir "connections.xml"

    # Workbench aberto regrava o connections.xml ao fechar e apagaria a conexão: fecha antes
    $open = @(Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue)
    if ($open.Count -gt 0) {
        Warn "MySQL Workbench já estava aberto; fechando para gravar a conexão (ele reabre em seguida)."
        foreach ($wp in $open) { try { [void]$wp.CloseMainWindow() } catch {} }
        if (-not (Wait-Until { -not (Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue) } 15)) {
            $script:WbReason = "o Workbench não fechou (talvez peça para salvar algo). Feche-o e rode de novo."
            return $false
        }
        Start-Sleep -Milliseconds 800
    }

    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }

    $doc = New-Object System.Xml.XmlDocument
    $doc.PreserveWhitespace = $true
    $list = $null
    $existing = (Test-Path $cx) -and ((Get-Item $cx).Length -gt 0)
    if ($existing) {
        try { $doc.Load($cx); $list = $doc.SelectSingleNode("//value[@content-struct-name='db.mgmt.Connection']") } catch { $list = $null }
        Backup-Once $cx
    }
    if (-not $list) {
        # sem arquivo (Workbench nunca aberto), vazio ou ilegível: começa um novo
        $doc = New-Object System.Xml.XmlDocument
        $doc.PreserveWhitespace = $true
        $doc.LoadXml('<?xml version="1.0"?><data><value type="list" content-type="object" content-struct-name="db.mgmt.Connection" key=""></value></data>')
        $list = $doc.SelectSingleNode("//value[@content-struct-name='db.mgmt.Connection']")
    }

    $conns = @($list.SelectNodes("value[@struct-name='db.mgmt.Connection']"))
    $target = $null
    foreach ($n in $conns) {
        $nm = $n.SelectSingleNode("value[@key='name']")
        if ($nm -and $nm.InnerText -eq $name) { $target = $n; break }
    }
    if (-not $target) {
        $template = $null
        foreach ($n in $conns) {
            $drv = $n.SelectSingleNode("link[@key='driver']")
            if ($drv -and $drv.InnerText -eq "com.mysql.rdbms.mysql.driver.native") { $template = $n; break }
        }
        if ($template) {
            $target = $template.CloneNode($true)          # mantém os campos próprios da versão instalada
            $target.SetAttribute("id", [guid]::NewGuid().ToString())
        } else {
            $target = New-WbConnectionNode $doc
        }
        [void]$list.AppendChild($target)
        Set-WbVal $doc $target "isDefault" "int" "0"
    }

    $params = $target.SelectSingleNode("value[@key='parameterValues']")
    if (-not $params) { $script:WbReason = "formato inesperado do connections.xml"; return $false }
    Set-WbVal $doc $target "name" "string" $name
    Set-WbVal $doc $target "hostIdentifier" "string" "Mysql@127.0.0.1:$port"
    Set-WbVal $doc $params "hostName" "string" "127.0.0.1"
    Set-WbVal $doc $params "port" "int" "$port"
    Set-WbVal $doc $params "userName" "string" $user
    Set-WbVal $doc $params "schema" "string" $schema

    $doc.Save($cx)

    # confere se ficou gravado
    $chk = New-Object System.Xml.XmlDocument
    $chk.Load($cx)
    foreach ($n in @($chk.SelectNodes("//value[@struct-name='db.mgmt.Connection']"))) {
        $nm = $n.SelectSingleNode("value[@key='name']")
        $pt = $n.SelectSingleNode("value[@key='parameterValues']/value[@key='port']")
        if ($nm -and $nm.InnerText -eq $name -and $pt -and $pt.InnerText -eq "$port") { return $true }
    }
    $script:WbReason = "a conexão não foi encontrada depois de salvar"
    return $false
}

try {
    Write-Host ""
    Write-Host "  PRECIO - ambiente XAMPP" -ForegroundColor White

    # ------------------------------------------------------------------ 1
    Step "Localizando o XAMPP"
    $xampp = Find-Xampp
    if (-not $xampp) { throw "XAMPP não encontrado. Instale em C:\xampp ou rode: xampp-start.ps1 -XamppPath D:\xampp" }
    Ok $xampp

    # ------------------------------------------------------------------ 2
    Step "Apache: escolhendo uma porta livre"
    Stop-XamppProcess $xampp "httpd"
    Start-Sleep -Milliseconds 800

    $apachePort = $null
    $order = @($PreferredApachePort, 8080, 8000, 8081, 8888)
    foreach ($p in $order) { if (Test-PortFree $p) { $apachePort = $p; break } }
    if (-not $apachePort) { $apachePort = Find-FreePort -Start 8100 }
    $sslPort = Find-FreePort -Start 443
    if ($sslPort -eq $apachePort) { $sslPort = Find-FreePort -Start ($apachePort + 1) }
    if ($apachePort -ne $PreferredApachePort) { Warn "Porta $PreferredApachePort ocupada (veja: netstat -ano | findstr :$PreferredApachePort). Usando $apachePort." }
    Ok "HTTP = $apachePort | HTTPS = $sslPort"

    $httpdConf = Join-Path $xampp "apache\conf\httpd.conf"
    $sslConf   = Join-Path $xampp "apache\conf\extra\httpd-ssl.conf"
    Backup-Once $httpdConf
    $txt = Read-Conf $httpdConf
    $txt = Set-SingleDirective $txt "Listen" "$apachePort"
    $txt = Set-SingleDirective $txt "ServerName" "localhost:$apachePort"
    Write-Conf $httpdConf $txt

    if (Test-Path $sslConf) {
        Backup-Once $sslConf
        $s = Read-Conf $sslConf
        $s = Set-SingleDirective $s "Listen" "$sslPort"
        $s = [regex]::Replace($s, '(?im)^([ \t]*<VirtualHost[ \t]+_default_:)\d+', "`${1}$sslPort")
        $s = Set-SingleDirective $s "ServerName" "localhost:$sslPort"
        Write-Conf $sslConf $s
    }

    $test = Invoke-Proc -File (Join-Path $xampp "apache\bin\httpd.exe") -Arguments @("-t") -WorkDir $xampp
    if ($test.Output -notmatch "Syntax OK") {
        Copy-Item "$httpdConf.precio.bak" $httpdConf -Force
        if (Test-Path "$sslConf.precio.bak") { Copy-Item "$sslConf.precio.bak" $sslConf -Force }
        throw "O Apache rejeitou a configuração (backup restaurado):`n$($test.Output)"
    }
    Ok "httpd.conf validado (Syntax OK)"

    # ------------------------------------------------------------------ 3
    Step "MySQL: escolhendo a porta"
    $myIni = Join-Path $xampp "mysql\bin\my.ini"
    $mysqlRunning = Test-XamppProcessRunning $xampp "mysqld"
    $iniText = Read-Conf $myIni
    $m = [regex]::Match($iniText, '(?im)^[ \t]*port[ \t]*=[ \t]*(\d+)')
    $currentPort = if ($m.Success) { [int]$m.Groups[1].Value } else { 3306 }

    if ($mysqlRunning) {
        $mysqlPort = $currentPort
        Ok "MySQL do XAMPP já está rodando na porta $mysqlPort (mantido)"
    } else {
        $mysqlPort = if (Test-PortFree 3306) { 3306 } else { Find-FreePort -Start 3307 }
        if ($mysqlPort -ne 3306) { Warn "Porta 3306 ocupada. Usando $mysqlPort." }
        Backup-Once $myIni
        $iniText = [regex]::Replace($iniText, '(?im)^([ \t]*port[ \t]*=[ \t]*)\d+', "`${1}$mysqlPort")
        Write-Conf $myIni $iniText
        Ok "MySQL = $mysqlPort"
    }

    # ------------------------------------------------------------------ 4
    Step "Copiando o projeto para htdocs\precio"
    $dest = Join-Path $xampp "htdocs\precio"
    $rc = Invoke-Proc -File "robocopy.exe" -Arguments @((Q $ProjectPath), (Q $dest), "/E", "/XO", "/XD", "documentos", ".git", "docker", "scripts", (Q "Bat's"), "/XF", "*.zip", "docker-compose.yml", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
    if ($rc.ExitCode -ge 8) { throw "Falha ao copiar o projeto (robocopy código $($rc.ExitCode)):`n$($rc.Output)" }
    Ok $dest

    $localCfg = Join-Path $dest "back-end\includes\db.local.php"
    if ($mysqlPort -ne 3306) {
        Set-Content -Path $localCfg -Encoding ASCII -Value "<?php return ['host' => '127.0.0.1', 'port' => $mysqlPort];"
        Ok "db.local.php gerado (porta $mysqlPort)"
    } elseif (Test-Path $localCfg) {
        Remove-Item $localCfg -Force
    }

    # ------------------------------------------------------------------ 5
    Step "Abrindo o XAMPP Control Panel e iniciando Apache + MySQL"
    $controlExe = Join-Path $xampp "xampp-control.exe"
    $controlIni = Join-Path $xampp "xampp-control.ini"

    if (-not (Test-IsAdmin)) {
        Warn "Rodando SEM administrador: o painel pode não aceitar o autostart. Rode de novo e aceite o UAC."
    }

    # 5a. O painel só lê o [Autostart] ao ABRIR (e regrava o .ini ao fechar normalmente).
    #     Se ele já estava aberto, fecha à força, grava o autostart e reabre.
    $openPanels = @(Get-Process -Name "xampp-control" -ErrorAction SilentlyContinue)
    if ($openPanels.Count -gt 0) {
        foreach ($pp in $openPanels) { Stop-Process -Id $pp.Id -Force -ErrorAction SilentlyContinue }
        if (Wait-Until { -not (Get-Process -Name "xampp-control" -ErrorAction SilentlyContinue) } 8) {
            Ok "Painel que já estava aberto foi fechado para reabrir com o autostart"
        } else {
            Warn "Não consegui fechar o painel que já estava aberto. Feche-o manualmente e rode de novo."
        }
    }

    # 5b. Liga o autostart de Apache + MySQL
    $autostartOk = $false
    try {
        Set-PanelAutostart $controlIni
        $autostartOk = $true
        Ok "Autostart de Apache + MySQL ativado no painel"
    } catch {
        Warn "Não consegui gravar o autostart em $controlIni ($($_.Exception.Message)). Apache e MySQL serão iniciados direto."
    }

    # 5c. Abre o painel (ele sobe Apache e MySQL sozinho ao abrir)
    $panelUp = $false
    if (Test-Path $controlExe) {
        try {
            Start-Process -FilePath $controlExe -WorkingDirectory $xampp
            $panelUp = Wait-Until { [bool](Get-Process -Name "xampp-control" -ErrorAction SilentlyContinue) } 12
            if ($panelUp) { Ok "XAMPP Control Panel aberto" } else { Warn "O XAMPP Control Panel não abriu." }
        } catch {
            Warn "Não consegui abrir o XAMPP Control Panel: $($_.Exception.Message)"
        }
    } else {
        Warn "xampp-control.exe não encontrado em $xampp"
    }

    # 5d. Dá tempo ao autostart do painel (só vale esperar se ele realmente pode funcionar)
    $waitPanel = if ($autostartOk -and $panelUp) { 30 } else { 2 }
    [void](Wait-Until { (Test-PortOpen $apachePort) -and (Test-PortOpen $mysqlPort) } $waitPanel)

    if ((Test-PortOpen $apachePort) -and (Test-PortOpen $mysqlPort)) {
        Ok "Apache e MySQL iniciados pelo painel (autostart) - devem aparecer em verde"
    }

    # 5e. Rede de segurança: o que o painel não subiu, sobe direto
    if (-not (Test-PortOpen $apachePort)) {
        if (Test-XamppProcessRunning $xampp "httpd") { [void](Wait-Until { Test-PortOpen $apachePort } 10) }
        if (-not (Test-PortOpen $apachePort)) {
            Warn "O painel não iniciou o Apache sozinho; iniciando diretamente..."
            Start-Process -FilePath (Join-Path $xampp "apache\bin\httpd.exe") -WorkingDirectory $xampp -WindowStyle Hidden
        }
    }
    if (-not (Test-PortOpen $mysqlPort)) {
        if (Test-XamppProcessRunning $xampp "mysqld") { [void](Wait-Until { Test-PortOpen $mysqlPort } 15) }
        if (-not (Test-PortOpen $mysqlPort)) {
            Warn "O painel não iniciou o MySQL sozinho; iniciando diretamente..."
            Start-Process -FilePath (Join-Path $xampp "mysql\bin\mysqld.exe") -WorkingDirectory $xampp -WindowStyle Hidden `
                -ArgumentList @("--defaults-file=" + (Q $myIni), "--standalone")
        }
    }

    if (Wait-Until { Test-PortOpen $apachePort } 25) { Ok "Apache no ar (porta $apachePort)" }
    else { throw "Apache não respondeu na porta $apachePort. Veja $xampp\apache\logs\error.log" }
    if (Wait-Until { Test-PortOpen $mysqlPort } 40) { Ok "MySQL no ar (porta $mysqlPort)" }
    else { throw "MySQL não respondeu na porta $mysqlPort. Veja $xampp\mysql\data\mysql_error.log" }

    # ------------------------------------------------------------------ 6
    Step "Importando o banco precio_db"
    $mysqlExe = Join-Path $xampp "mysql\bin\mysql.exe"
    $cli = @("-u", "root", "-h", "127.0.0.1", "-P", "$mysqlPort", "--protocol=tcp", "--default-character-set=utf8mb4")
    if ($MysqlPassword) { $cli += "-p$MysqlPassword" }

    $imp = Invoke-Proc -File $mysqlExe -Arguments $cli -StdinFile (Join-Path $ProjectPath "back-end\setup.sql")
    if ($imp.ExitCode -ne 0) { throw "Falha ao importar o setup.sql:`n$($imp.Output)`nSe o root tem senha, rode com -MysqlPassword SUASENHA." }

    $chk = Invoke-Proc -File $mysqlExe -Arguments ($cli + @("-N", "-B", "-e", (Q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='precio_db'")))
    Ok "Banco precio_db pronto ($($chk.Output.Trim()) tabelas)"

    # ------------------------------------------------------------------ 7
    $connName = "Precio (XAMPP)"
    if (-not $NoWorkbench) {
        Step "MySQL Workbench"
        $wb = Find-Workbench
        if (-not $wb) {
            Warn "MySQL Workbench não encontrado. Instale-o e conecte em 127.0.0.1:$mysqlPort (usuário root)."
        } else {
            $added = $false
            try { $added = Set-WorkbenchConnection $connName $mysqlPort "precio_db" "root" }
            catch { $script:WbReason = $_.Exception.Message }
            if ($added) {
                Ok "Conexão '$connName' criada/atualizada (127.0.0.1:$mysqlPort, root, schema precio_db)"
                Start-Unelevated $wb ("-query " + (Q $connName))
                Write-Host "    Se o Workbench pedir senha: deixe em branco e clique OK (o root do XAMPP não tem senha)." -ForegroundColor Gray
            } else {
                Start-Unelevated $wb
                Warn "Conexão não criada automaticamente: $($script:WbReason)"
                Warn "Manual: + > Hostname 127.0.0.1, Port $mysqlPort, User root, senha vazia, Default Schema precio_db."
            }
            Ok "Workbench aberto"
        }
    }

    # ------------------------------------------------------------------ 8
    $site = if ($apachePort -eq 80) { "http://localhost/precio/front-end/" } else { "http://localhost:$apachePort/precio/front-end/" }
    if (-not $NoBrowser) {
        # elevado: passa pelo Explorer para abrir no navegador normal (Chrome/Edge elevado costuma falhar)
        if (Test-IsAdmin) { Start-Process -FilePath "explorer.exe" -ArgumentList $site } else { Start-Process $site }
    }

    Write-Host ""
    Write-Host "============================================================" -ForegroundColor Green
    Write-Host "  Site ........: $site"
    Write-Host "  MySQL .......: 127.0.0.1:$mysqlPort  (root, sem senha)  banco: precio_db"
    Write-Host "  Para parar ..: Bat's\KILL.bat  (desliga tudo e APAGA o banco)"
    Write-Host "============================================================" -ForegroundColor Green
    Finish 0
}
catch {
    Write-Host ""
    Write-Host "[ERRO] $($_.Exception.Message)" -ForegroundColor Red
    Finish 1
}