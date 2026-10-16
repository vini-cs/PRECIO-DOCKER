@echo off
setlocal
title Precio - remover containers, network e imagens
call "%~dp0config.bat"
call "%BATDIR%_docker_pronto.bat" || (pause & exit /b 1)

echo Removendo containers...
for %%C in (%C_NGINX% %C_WEB% %C_SCRAPER% %C_DB%) do (
    docker rm -f %%C >nul 2>&1 && echo   removido: %%C
)

echo Removendo network...
docker network rm %NETWORK% >nul 2>&1 && echo   removida: %NETWORK%

echo Removendo imagens...
for %%I in (%IMG_NGINX% %IMG_WEB% %IMG_SCRAPER% %IMG_DB%) do (
    docker rmi -f %%I >nul 2>&1 && echo   removida: %%I
)

echo.
choice /c SN /d N /t 15 /m "Apagar tambem os VOLUMES (banco de dados, cache e logs)? [padrao: N em 15s]"
if errorlevel 2 goto :sem_volumes
for %%V in (%VOL_DB% %VOL_CACHE% %VOL_LOGS%) do (
    docker volume rm %%V >nul 2>&1 && echo   volume removido: %%V
)
goto :fim

:sem_volumes
echo Volumes mantidos (o banco de dados nao foi apagado).

:fim
echo.
echo Concluido.
pause
