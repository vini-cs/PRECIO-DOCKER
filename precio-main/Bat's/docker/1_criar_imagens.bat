@echo off
setlocal
title Precio - criar imagens Docker
call "%~dp0config.bat"
call "%BATDIR%_docker_pronto.bat" || (pause & exit /b 1)

pushd "%BATDIR%..\.."

echo.
echo [1/4] Imagem do banco de dados  (%IMG_DB%  - base mysql:8.0)
docker build -t %IMG_DB% -f docker\mysql\Dockerfile . || goto :erro

echo.
echo [2/4] Imagem PHP/Apache  (%IMG_WEB%  - base php:8.2-apache)
docker build -t %IMG_WEB% -f docker\php\Dockerfile . || goto :erro

echo.
echo [3/4] Imagem do scraper  (%IMG_SCRAPER%  - base python:3.11-slim)
docker build -t %IMG_SCRAPER% -f docker\scraper\Dockerfile . || goto :erro

echo.
echo [4/4] Imagem do nginx  (%IMG_NGINX%  - base nginx:alpine)
docker build -t %IMG_NGINX% -f docker\nginx\Dockerfile . || goto :erro

popd
echo.
echo Imagens criadas com sucesso:
docker images --filter "reference=precio-*"
if /i not "%~1"=="auto" pause
exit /b 0

:erro
popd
echo.
echo [ERRO] Falha ao criar as imagens. Veja a mensagem acima.
pause
exit /b 1
