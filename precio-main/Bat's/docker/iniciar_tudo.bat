@echo off
setlocal
title Precio - iniciar tudo no Docker
rem Sobe Apache (PHP) + MySQL + nginx em containers, SEM precisar do XAMPP.
rem Importa o banco, abre o MySQL Workbench ja conectado e abre o site (igual ao start_dev.bat).
call "%~dp0config.bat"
call "%BATDIR%1_criar_imagens.bat" auto || exit /b 1
call "%BATDIR%2_criar_network.bat" auto || exit /b 1
call "%BATDIR%3_criar_containers.bat" auto || exit /b 1

echo.
echo ==^> Importando o banco %DB_NAME% (back-end\setup.sql)
rem Sempre importa: o setup.sql e idempotente (IF NOT EXISTS). Cobre o caso de um volume antigo ja existir vazio.
docker exec -i -e MYSQL_PWD=%DB_ROOT_PASS% %C_DB% mysql -uroot --default-character-set=utf8mb4 < "%BATDIR%..\..\back-end\setup.sql" 2>nul
if errorlevel 1 (
    echo     [ERRO] Falha ao importar o setup.sql. Veja:  docker logs %C_DB%
) else (
    for /f "delims=" %%N in ('docker exec -e MYSQL_PWD=%DB_ROOT_PASS% %C_DB% mysql -uroot -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%DB_NAME%'" 2^>nul') do echo     [ok] Banco %DB_NAME% pronto com %%N tabelas
)

rem Roda da raiz do projeto (o caminho "Bat's" tem apostrofo) e desbloqueia os .ps1 baixados do zip (evita o aviso de seguranca)
pushd "%BATDIR%..\.."
powershell -NoProfile -Command "Get-ChildItem scripts -Filter *.ps1 | Unblock-File" >nul 2>&1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\docker-open.ps1 -DbUser root -DbPass %DB_ROOT_PASS% -DbName %DB_NAME%
popd
echo.
pause
exit /b 0
