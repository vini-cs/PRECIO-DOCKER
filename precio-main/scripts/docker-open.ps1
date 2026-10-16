<#
 Precio (Docker) - depois que os containers subiram:
   1. descobre as portas publicadas no host (site = precio-nginx, MySQL = precio-db)
   2. espera o site responder
   3. cria/atualiza a conexao "Precio (Docker)" no MySQL Workbench e abre o Workbench nela
   4. abre o site no navegador
 Nao precisa de administrador.
#>
param(
    [int]$SitePort = 0,
    [int]$DbPort = 0,
    [string]$DbUser = "root",
    [string]$DbName = "precio_db",
    [string]$DbPass = "root",
    [switch]$NoWorkbench,
    [switch]$NoBrowser
)

# "Continue": avisos no stderr de docker/mysql nao podem derrubar o script (no PowerShell 5 o "Stop" os trata como erro)
$ErrorActionPreference = "Continue"
$PSNativeCommandUseErrorActionPreference = $false
$AppData = $env:APPDATA
$ConnName = "Precio (Docker)"
$Latin1 = [System.Text.Encoding]::GetEncoding(28591)

function Step($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor Cyan }
function Ok($m)   { Write-Host "    [ok] $m" -ForegroundColor Green }
function Warn($m) { Write-Host "    [!]  $m" -ForegroundColor Yellow }
function Q([string]$s) { return '"' + $s + '"' }

# porta publicada no host por um container (ex.: precio-nginx 80/tcp -> 8080)
function Get-HostPort([string]$container, [string]$inner) {
    try {
        $out = & docker port $container $inner 2>$null
        foreach ($l in @($out)) {
            if ("$l" -match ':(\d+)\s*$') { return [int]$Matches[1] }
        }
    } catch {}
    return 0
}

function Wait-Until([scriptblock]$cond, [int]$seconds) {
    $end = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $end) {
        if (& $cond) { return $true }
        Start-Sleep -Milliseconds 700
    }
    return [bool](& $cond)
}

function Backup-Once([string]$path) {
    $bak = "$path.precio.bak"
    if (-not (Test-Path $bak)) { Copy-Item $path $bak }
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

function Set-WorkbenchConnection([string]$name, [int]$port, [string]$schema, [string]$user) {
    $script:WbReason = ""
    $dir = Join-Path $AppData "MySQL\Workbench"
    $cx  = Join-Path $dir "connections.xml"

    # Workbench aberto regrava o connections.xml ao fechar e apagaria a conexao: fecha antes
    $open = @(Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue)
    if ($open.Count -gt 0) {
        Warn "MySQL Workbench ja estava aberto; fechando para gravar a conexao (ele reabre em seguida)."
        foreach ($wp in $open) { try { [void]$wp.CloseMainWindow() } catch {} }
        if (-not (Wait-Until { -not (Get-Process -Name "MySQLWorkbench" -ErrorAction SilentlyContinue) } 15)) {
            $script:WbReason = "o Workbench nao fechou (talvez peca para salvar algo). Feche-o e rode de novo."
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
            $target = $template.CloneNode($true)
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

    $chk = New-Object System.Xml.XmlDocument
    $chk.Load($cx)
    foreach ($n in @($chk.SelectNodes("//value[@struct-name='db.mgmt.Connection']"))) {
        $nm = $n.SelectSingleNode("value[@key='name']")
        $pt = $n.SelectSingleNode("value[@key='parameterValues']/value[@key='port']")
        if ($nm -and $nm.InnerText -eq $name -and $pt -and $pt.InnerText -eq "$port") { return $true }
    }
    $script:WbReason = "a conexao nao foi encontrada depois de salvar"
    return $false
}

try {
    Step "Descobrindo as portas dos containers"
    if ($SitePort -le 0) { $SitePort = Get-HostPort "precio-nginx" "80/tcp" }
    if ($DbPort   -le 0) { $DbPort   = Get-HostPort "precio-db"    "3306/tcp" }
    if ($SitePort -le 0) { throw "Nao achei a porta do site (container precio-nginx esta rodando?)." }
    if ($DbPort   -le 0) { throw "Nao achei a porta do MySQL (container precio-db esta rodando?)." }
    $site = "http://localhost:$SitePort/front-end/"
    Ok "site: $site | MySQL: 127.0.0.1:$DbPort"

    Step "Conferindo os servicos (Apache e MySQL nos containers, sem XAMPP)"
    foreach ($svc in @(@("precio-web","Apache (PHP)"), @("precio-db","MySQL"))) {
        $cname = $svc[0]; $label = $svc[1]
        $fmt = "{{.State.Status}}/{{if .State.Health}}{{.State.Health.Status}}{{end}}"
        $st = "$(& docker inspect --format $fmt $cname 2>$null)"
        if ($st -notmatch '^running') {
            Warn "$label parado; iniciando..."
            & docker start $cname 2>&1 | Out-Null
            Start-Sleep -Seconds 5
            $st = "$(& docker inspect --format $fmt $cname 2>$null)"
        }
        if ($st -match '^running') { Ok "$label rodando ($st)" } else { Warn "$label NAO esta rodando: veja docker logs $cname" }
    }
    $tabs = (& docker exec -e "MYSQL_PWD=$DbPass" precio-db mysql -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DbName'" 2>$null)
    if ("$tabs".Trim() -match '^\d+$' -and [int]"$tabs".Trim() -gt 0) { Ok "Banco $DbName com $("$tabs".Trim()) tabelas" }
    else { Warn "Banco $DbName vazio ou inacessivel (rode iniciar_tudo.bat de novo)." }

    Step "Esperando o site responder"
    $up = Wait-Until {
        try { $r = Invoke-WebRequest -Uri $site -UseBasicParsing -TimeoutSec 3; return ($r.StatusCode -lt 500) }
        catch { return $false }
    } 60
    if ($up) { Ok "site no ar" } else { Warn "o site ainda nao respondeu; abrindo mesmo assim (atualize a pagina em alguns segundos)." }

    if (-not $NoWorkbench) {
        Step "MySQL Workbench"
        $wb = Find-Workbench
        if (-not $wb) {
            Warn "MySQL Workbench nao encontrado. Instale-o e conecte em 127.0.0.1:$DbPort (usuario $DbUser, senha $DbPass)."
        } else {
            $added = $false
            try { $added = Set-WorkbenchConnection $ConnName $DbPort $DbName $DbUser }
            catch { $script:WbReason = $_.Exception.Message }
            if ($added) {
                Ok "Conexao '$ConnName' criada/atualizada (127.0.0.1:$DbPort, $DbUser, schema $DbName)"
                Start-Process -FilePath $wb -ArgumentList ("-query " + (Q $ConnName))
                Write-Host "    Se o Workbench pedir senha: digite $DbPass e clique OK." -ForegroundColor Gray
            } else {
                Start-Process -FilePath $wb
                Warn "Conexao nao criada automaticamente: $($script:WbReason)"
                Warn "Manual: + > Hostname 127.0.0.1, Port $DbPort, User $DbUser, senha $DbPass, Default Schema $DbName."
            }
            Ok "Workbench aberto"
        }
    }

    if (-not $NoBrowser) { Start-Process $site; Ok "Navegador aberto" }

    Write-Host ""
    Write-Host "============================================================" -ForegroundColor Green
    Write-Host "  Site ........: $site"
    Write-Host "  MySQL .......: 127.0.0.1:$DbPort  ($DbUser / $DbPass)  banco: $DbName"
    Write-Host "  Para parar ..: Bat's\docker\parar_tudo.bat"
    Write-Host "============================================================" -ForegroundColor Green
    exit 0
}
catch {
    Write-Host ""
    Write-Host "[ERRO] $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
