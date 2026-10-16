@echo off
setlocal
title Precio - criar network Docker
call "%~dp0config.bat"
call "%BATDIR%_docker_pronto.bat" || (pause & exit /b 1)

docker network inspect %NETWORK% >nul 2>&1
if not errorlevel 1 goto :existe

docker network create --driver bridge %NETWORK%
if errorlevel 1 (
    echo [ERRO] Nao foi possivel criar a network %NETWORK%.
    pause
    exit /b 1
)
echo Network %NETWORK% criada.
goto :fim

:existe
echo Network %NETWORK% ja existe.

:fim
if /i not "%~1"=="auto" pause
exit /b 0
