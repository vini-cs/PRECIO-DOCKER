@echo off
setlocal
title Precio - criar e iniciar containers
call "%~dp0config.bat"
call "%BATDIR%_docker_pronto.bat" || (pause & exit /b 1)

pushd "%BATDIR%..\.."

rem --- imagens e network precisam existir ---
docker image inspect %IMG_WEB% >nul 2>&1 || call "%BATDIR%1_criar_imagens.bat" auto
docker image inspect %IMG_WEB% >nul 2>&1 || goto :erro
docker network inspect %NETWORK% >nul 2>&1 || call "%BATDIR%2_criar_network.bat" auto

rem --- remove containers antigos (os dados ficam nos volumes) ---
for %%C in (%C_NGINX% %C_WEB% %C_SCRAPER% %C_DB%) do docker rm -f %%C >nul 2>&1

rem --- escolhe portas livres no host ---
set "PORT_WEB="
set "PORT_DB="
for /f "delims=" %%P in ('powershell -NoProfile -ExecutionPolicy Bypass -File scripts\free-port.ps1 -Start %PORT_WEB_PREF%') do set "PORT_WEB=%%P"
for /f "delims=" %%P in ('powershell -NoProfile -ExecutionPolicy Bypass -File scripts\free-port.ps1 -Start %PORT_DB_PREF%') do set "PORT_DB=%%P"
if not defined PORT_WEB set "PORT_WEB=%PORT_WEB_PREF%"
if not defined PORT_DB set "PORT_DB=%PORT_DB_PREF%"
echo Portas escolhidas: site=%PORT_WEB%  mysql=%PORT_DB%

echo.
echo [1/4] Container do banco (%C_DB%)
docker run -d --name %C_DB% --network %NETWORK% --restart unless-stopped ^
    -e MYSQL_ROOT_PASSWORD=%DB_ROOT_PASS% -e MYSQL_DATABASE=%DB_NAME% ^
    -e MYSQL_USER=%DB_USER% -e MYSQL_PASSWORD=%DB_PASS% -e TZ=America/Sao_Paulo ^
    -p %PORT_DB%:3306 -v %VOL_DB%:/var/lib/mysql ^
    %IMG_DB% || goto :erro

echo Aguardando o MySQL ficar pronto (na primeira vez leva ~30s, pois cria as tabelas)...
set /a _n=0
:espera_db
set "_st="
for /f "delims=" %%S in ('docker inspect --format "{{.State.Health.Status}}" %C_DB%') do set "_st=%%S"
if "%_st%"=="healthy" goto :db_ok
set /a _n+=1
if %_n% geq 90 goto :erro_db
timeout /t 2 /nobreak >nul
goto :espera_db

:db_ok
echo MySQL pronto.

echo.
echo [2/4] Container PHP/Apache (%C_WEB%)
docker run -d --name %C_WEB% --network %NETWORK% --restart unless-stopped ^
    -e DB_HOST=%C_DB% -e DB_PORT=3306 -e DB_NAME=%DB_NAME% -e DB_USER=%DB_USER% -e DB_PASS=%DB_PASS% ^
    -v %VOL_CACHE%:/var/www/html/back-end/cache -v %VOL_LOGS%:/var/www/html/back-end/logs ^
    %IMG_WEB% || goto :erro

echo.
echo [3/4] Container do scraper (%C_SCRAPER%)
docker run -d --name %C_SCRAPER% --network %NETWORK% --restart unless-stopped %IMG_SCRAPER% || goto :erro

echo.
echo [4/4] Container nginx (%C_NGINX%)
docker run -d --name %C_NGINX% --network %NETWORK% --restart unless-stopped ^
    -p %PORT_WEB%:80 %IMG_NGINX% || goto :erro

echo.
echo Aguardando o Apache ficar pronto...
set /a _n=0
:espera_web
set "_st="
for /f "delims=" %%S in ('docker inspect --format "{{.State.Health.Status}}" %C_WEB%') do set "_st=%%S"
if "%_st%"=="healthy" goto :web_ok
set /a _n+=1
if %_n% geq 40 goto :web_ok
timeout /t 2 /nobreak >nul
goto :espera_web

:web_ok
popd
echo.
echo ============================================================
echo  Precio rodando no Docker
echo   Site .......: http://localhost:%PORT_WEB%/front-end/
echo   MySQL ......: 127.0.0.1:%PORT_DB%  (root / %DB_ROOT_PASS%  ou  %DB_USER% / %DB_PASS%)
echo   Banco ......: %DB_NAME%
echo   Scraper ....: scraper.bat "rtx 4070"
echo ============================================================
echo.
docker ps --filter "network=%NETWORK%" --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"
rem (o site nao abre sozinho aqui; use o link acima. Quem abre o navegador e o Bat's\start_dev.bat)
if /i not "%~1"=="auto" pause
exit /b 0

:erro_db
echo [ERRO] O MySQL nao ficou pronto. Veja:  docker logs %C_DB%
goto :erro

:erro
popd
echo.
echo [ERRO] Falha ao criar os containers. Veja a mensagem acima.
pause
exit /b 1
