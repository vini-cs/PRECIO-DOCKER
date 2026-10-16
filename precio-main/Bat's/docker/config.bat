@echo off
rem ============================================================
rem  Configuracao compartilhada pelos scripts Docker do Precio
rem ============================================================
set "BATDIR=%~dp0"

set "NETWORK=precio-net"
set "VER=1.0"

rem --- Imagens ---
set "IMG_DB=precio-db:%VER%"
set "IMG_WEB=precio-web:%VER%"
set "IMG_SCRAPER=precio-scraper:%VER%"
set "IMG_NGINX=precio-nginx:%VER%"

rem --- Containers ---
set "C_DB=precio-db"
set "C_WEB=precio-web"
set "C_SCRAPER=precio-scraper"
set "C_NGINX=precio-nginx"

rem --- Volumes (dados persistem mesmo removendo os containers) ---
set "VOL_DB=precio_db_data"
set "VOL_CACHE=precio_cache"
set "VOL_LOGS=precio_logs"

rem --- Banco de dados ---
set "DB_ROOT_PASS=root"
set "DB_NAME=precio_db"
set "DB_USER=precio"
set "DB_PASS=precio123"

rem --- Portas preferidas no host (se estiverem ocupadas, o script escolhe a proxima livre) ---
set "PORT_WEB_PREF=8080"
set "PORT_DB_PREF=3307"
