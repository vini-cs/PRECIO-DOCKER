@echo off
rem Garante que o Docker esta instalado e rodando (abre o Docker Desktop se preciso).
where docker >nul 2>&1
if errorlevel 1 (
    echo [ERRO] Docker nao encontrado. Instale o Docker Desktop: https://www.docker.com/products/docker-desktop
    exit /b 1
)

docker info >nul 2>&1
if not errorlevel 1 exit /b 0

echo Docker nao esta respondendo. Tentando abrir o Docker Desktop...
if exist "%ProgramFiles%\Docker\Docker\Docker Desktop.exe" start "" "%ProgramFiles%\Docker\Docker\Docker Desktop.exe"

set /a _tent=0
:espera_docker
timeout /t 3 /nobreak >nul
docker info >nul 2>&1
if not errorlevel 1 exit /b 0
set /a _tent+=1
if %_tent% lss 60 goto espera_docker

echo [ERRO] O Docker nao iniciou a tempo. Abra o Docker Desktop manualmente e tente de novo.
exit /b 1
