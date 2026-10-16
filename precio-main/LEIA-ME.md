# 🛒 Precio — Guia de instalação e testes

> **🎯 Objetivo:** baixar o repositório **PRECIO-DOCKER** do GitHub, abrir o projeto no **Visual Studio Code** e executá-lo para testar.
>
> **📌 Você pode escolher:** clonar pelo VS Code, clonar pelo terminal ou baixar os arquivos em ZIP. Depois, siga as instruções de **Docker** ou **XAMPP** mais abaixo.

---

## 🧩 1. Baixar o projeto do GitHub

O repositório **PRECIO-DOCKER** está publicado no GitHub como público (*Public*). Escolha uma das opções abaixo.

### 🟦 Opção A — Clonar pelo VS Code

1. Abra a página do repositório **PRECIO-DOCKER** no GitHub.
2. Clique no botão verde **Code** e selecione a opção **HTTPS**.
3. Clique no ícone para copiar o endereço do repositório.
4. Abra o **Visual Studio Code** no computador.
5. Abra a paleta de comandos com `Ctrl + Shift + P`.
6. Digite e selecione **Git: Clone**.
7. Cole o endereço HTTPS que copiou do GitHub e pressione `Enter`.
8. Escolha uma pasta do computador onde deseja guardar o projeto. Aguarde o download terminar.
9. Quando o VS Code perguntar se deseja abrir o repositório clonado, clique em **Open** (ou **Abrir**).
10. Se aparecer uma solicitação de confiança na pasta, confirme somente se você confia no projeto e na origem dos arquivos.

> Se a opção **Git: Clone** não aparecer ou o VS Code informar que o Git não está instalado, instale o Git para Windows e tente novamente: https://git-scm.com/download/win

### 🟩 Opção B — Clonar pelo terminal (Git Bash, PowerShell ou terminal do VS Code)

1. Na página do repositório **PRECIO-DOCKER** no GitHub, clique em **Code → HTTPS** e copie o endereço do repositório.
2. Abra o **Terminal** do Windows (PowerShell ou Git Bash) ou o terminal integrado do VS Code (**Terminal → New Terminal**).
3. Vá até a pasta onde deseja salvar o projeto. Por exemplo, para usar a pasta `Projetos` dentro do seu usuário:

   ```powershell
   cd "$HOME\Documents"
   ```

   Você pode escolher outra pasta; o importante é executar o próximo comando no local em que deseja criar a pasta do projeto.

4. Execute o comando abaixo, substituindo `COLE_A_URL_HTTPS_AQUI` pelo endereço copiado do GitHub:

   ```bash
   git clone COLE_A_URL_HTTPS_AQUI
   ```

   Exemplo de formato (não use este endereço fictício literalmente): `git clone https://github.com/USUARIO/REPOSITORIO.git`.

5. Entre na pasta que o Git criou. O nome normalmente é o mesmo do repositório:

   ```bash
   cd PRECIO-DOCKER
   ```

   Se a pasta tiver outro nome, use o nome que apareceu após o clone.

6. Abra a pasta atual no VS Code:

   ```bash
   code .
   ```

   Se `code .` não for reconhecido, abra o VS Code manualmente e use **File (Arquivo) → Open Folder (Abrir Pasta)** para selecionar a pasta clonada.

> **Pré-requisito:** o Git precisa estar instalado. Se `git` não for reconhecido, instale o Git para Windows em https://git-scm.com/download/win, feche e reabra o terminal e tente novamente.

### 🟨 Opção C — Baixar como ZIP

1. Na página do repositório **PRECIO-DOCKER** no GitHub, clique em **Code** e depois em **Download ZIP**.
2. Quando o download terminar, extraia o arquivo ZIP para uma pasta do computador. Não tente executar o projeto de dentro do ZIP.
3. No VS Code, clique em **File (Arquivo) → Open Folder (Abrir Pasta)** e selecione a pasta extraída que contém os arquivos do projeto.
4. Confira se você abriu a pasta principal do projeto — é nela que devem ficar arquivos como `docker-compose.yml` e as pastas do projeto.

## 🚀 2. Executar e testar o projeto

Você pode executar o Precio de duas maneiras, descritas em detalhes abaixo:

- **Docker (recomendado para testar os containers):** instale e abra o Docker Desktop. Na pasta do projeto, localize `Bat's\docker\iniciar_tudo.bat` e execute-o. O script cria/inicia os recursos necessários e informa o endereço do site no final. Se preferir usar o terminal, abra o terminal integrado do VS Code em **Terminal → New Terminal**, confirme que ele está na pasta principal do projeto e execute `docker compose up -d --build`.
- **XAMPP (desenvolvimento local):** se for usar o XAMPP, siga as instruções da seção **XAMPP** abaixo e execute `Bat's\start_dev.bat` conforme descrito.

> **⚠️ Importante:** para executar os scripts `.bat`, use a pasta local que você clonou ou extraiu. Não é necessário criar os arquivos manualmente nem copiar os arquivos um por um do GitHub. Para receber alterações futuras do repositório clonado, abra o terminal na pasta do projeto e execute `git pull` (desde que não existam alterações locais conflitantes).

---

# Precio — Implantação (Docker + XAMPP)

Sistema web **Precio** (PHP + MySQL) pronto para rodar de duas formas: **Docker** (avaliação prática) ou **XAMPP** (desenvolvimento).

---

## 1. Docker

Requisito: Docker Desktop instalado (os scripts abrem o Docker Desktop sozinhos se ele estiver fechado).

| Script (`Bat's\docker\`) | O que faz |
|---|---|
| `iniciar_tudo.bat` | Faz tudo em sequência (1 → 2 → 3) e, no final, **abre o site no navegador** e o **MySQL Workbench** já na conexão `Precio (Docker)` (127.0.0.1, porta do MySQL, `root` / senha `root`, schema `precio_db`). Substitui o XAMPP: não precisa dele instalado (`scripts\docker-open.ps1`) |
| `1_criar_imagens.bat` | Cria as 4 imagens |
| `2_criar_network.bat` | Cria a network `precio-net` |
| `3_criar_containers.bat` | Cria/inicia os containers, espera o MySQL ficar pronto e mostra a URL do site |
| `parar_tudo.bat` | Para os containers (mantém imagens e dados) |
| `remover_tudo.bat` | Remove containers, network e imagens (pergunta se apaga os volumes/dados) |
| `scraper.bat "rtx 4070"` | Executa o `scraper.py` dentro do container do scraper |

Alternativa sem `.bat`: `docker compose up -d --build` (na raiz do projeto).

### Arquitetura

```
 navegador ──► precio-nginx (nginx:alpine) ──► precio-web (php:8.2-apache) ──► precio-db (mysql:8.0)
   :8080*            proxy reverso                 front-end + back-end              banco precio_db
                                  precio-scraper (python:3.11-slim)  ← docker exec ... scraper.py
                              (todos na network precio-net)
```
\* Se a porta 8080 estiver ocupada, o script escolhe a próxima livre e mostra a URL no final.

| Imagem | Container | Função |
|---|---|---|
| `precio-db:1.0` (mysql:8.0) | `precio-db` | Banco; cria as tabelas via `setup.sql` na 1ª execução |
| `precio-web:1.0` (php:8.2-apache) | `precio-web` | API PHP (`api.php`, `admin_api.php`, `includes/`) e front-end |
| `precio-scraper:1.0` (python:3.11-slim) | `precio-scraper` | Roda o `scraper.py` |
| `precio-nginx:1.0` (nginx:alpine) | `precio-nginx` | Proxy na frente do Apache; única porta publicada do site |

Volumes (os dados sobrevivem à remoção dos containers): `precio_db_data`, `precio_cache`, `precio_logs`.

### Parâmetros configurados (variáveis de ambiente)

| Variável | Valor | Onde |
|---|---|---|
| `DB_HOST` / `DB_PORT` | `precio-db` / `3306` | `precio-web` (lido por `back-end/includes/db.php`) |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `precio_db` / `precio` / `precio123` | `precio-web` e `precio-db` |
| `MYSQL_ROOT_PASSWORD` | `root` | `precio-db` |
| Porta do MySQL no host | `3307` (ou próxima livre) | para usar no MySQL Workbench |

Tudo pode ser alterado em `Bat's\docker\config.bat` (ou no `docker-compose.yml`).

### Migração de dados

O banco é criado do zero pelo `setup.sql` (o zip não contém dados de usuários). O primeiro usuário cadastrado vira admin. Para trazer dados de outro ambiente: `mysqldump -u root precio_db > backup.sql` no ambiente antigo e importe com `docker exec -i precio-db mysql -uroot -proot precio_db < backup.sql`.

### Problemas comuns

- *Porta ocupada*: o script já escolhe outra; veja a URL impressa no final.
- *Ver logs*: `docker logs precio-web` / `docker logs precio-db`.
- *Recriar o banco do zero*: `remover_tudo.bat` (responda **S** para apagar volumes) e `iniciar_tudo.bat`.

---

## 2. XAMPP

Execute **`Bat's\start_dev.bat`** e **aceite o aviso do Windows (UAC)** — o script precisa de administrador para o XAMPP subir sozinho. Ele:

1. localiza o XAMPP e **escolhe uma porta livre para o Apache** (80, senão 8080, 8000, 8081…), ajusta `httpd.conf` / `httpd-ssl.conf` e valida com `httpd -t` (faz backup `*.precio.bak`);
2. escolhe a porta do MySQL (3306 se livre);
3. copia o projeto para `htdocs\precio`;
4. **fecha o XAMPP Control Panel se já estiver aberto**, liga o autostart de Apache + MySQL no `xampp-control.ini` e **reabre o painel** (ele sobe os dois sozinho e eles aparecem em verde); se o painel não conseguir, o script inicia Apache/MySQL direto;
5. importa o banco `precio_db` completo (`back-end\setup.sql`, idempotente);
6. cria (ou atualiza) a conexão `Precio (XAMPP)` no **MySQL Workbench** — 127.0.0.1, porta do MySQL, `root`, schema `precio_db`; funciona mesmo com o Workbench nunca aberto ou já aberto (ele é fechado e reaberto) — abre o Workbench nessa conexão e **abre o site** (só o `start_dev.bat` abre o site).

`Bat's\KILL.bat` (pede administrador) **fecha o MySQL Workbench e remove a conexão `Precio (XAMPP)`**, **apaga o banco `precio_db`**, desliga o MySQL (shutdown limpo) e o Apache e fecha o painel do XAMPP, conferindo cada etapa. Para manter o banco: `Bat's\KILL.bat -KeepDatabase`; para manter o painel aberto: `-KeepPanel`; para manter o Workbench e a conexão: `-KeepWorkbench`. O `start_dev.bat` recria o banco do zero. Se o root do MySQL tiver senha: `powershell -File scripts\xampp-start.ps1 -MysqlPassword SUASENHA`.

**Por que o Apache não iniciava:** o `start_dev.bat` antigo abria `localhost:8080`, e essa porta estava em uso por `wslrelay.exe` (WSL/Docker). O aviso vermelho do Tomcat no painel é só isso e não afeta o Precio.

**Por que o XAMPP não abria Apache/MySQL sozinho (corrigido):** o painel só lê o autostart *ao abrir*, então se ele já estivesse aberto o autostart era ignorado; além disso, sem administrador o script não conseguia gravar o `xampp-control.ini` nem fechar um painel aberto como administrador. Agora o script se eleva (UAC), reinicia o painel já com o autostart ligado e só usa a partida direta como plano B. Se negar o UAC, ele continua, mas o autostart pode não funcionar. Parâmetros úteis: `-NoElevate` (não pedir admin), `-NoPause`, `-NoBrowser`, `-NoWorkbench`, `-XamppPath D:\xampp`.
