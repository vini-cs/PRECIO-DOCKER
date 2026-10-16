@echo off
setlocal
title Precio - parar containers
call "%~dp0config.bat"
call "%BATDIR%_docker_pronto.bat" || (pause & exit /b 1)

echo Parando containers (imagens, network e dados sao mantidos)...
for %%C in (%C_NGINX% %C_WEB% %C_SCRAPER% %C_DB%) do (
    docker stop %%C >nul 2>&1 && echo   parado: %%C
)
echo.
echo Para subir de novo: 3_criar_containers.bat
pause
