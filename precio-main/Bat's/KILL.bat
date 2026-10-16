@echo off
setlocal
title Precio - encerrar ambiente XAMPP
rem Fecha o Workbench e remove a conexao dele, apaga o banco precio_db, desliga MySQL e Apache e fecha o painel do XAMPP.
rem Pede permissao de administrador (UAC). Opcoes: -KeepDatabase  -KeepPanel  -KeepWorkbench  -MysqlPassword SENHA
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\scripts\xampp-stop.ps1" %*
if errorlevel 1 pause
