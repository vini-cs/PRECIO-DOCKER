@echo off
setlocal
rem Uso:  scraper.bat "rtx 4070"
call "%~dp0config.bat"
if "%~1"=="" (
    set /p "TERMO=Produto para buscar: "
) else (
    set "TERMO=%~1"
)
docker exec %C_SCRAPER% python scraper.py "%TERMO%"
pause
