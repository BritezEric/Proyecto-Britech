@echo off
REM Mantiene el bot de Telegram escuchando (polling). Si PHP se corta, reinicia solo.
REM Dejar esta ventana ABIERTA toda la expo. Necesita el tunel de n8n arriba para el asistente.
title Britech - Bot de Telegram
cd /d "%~dp0"

REM Busca el PHP de Laragon; si no, usa el php del PATH.
set "PHP=php"
for /d %%D in ("C:\laragon\bin\php\php-*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"

:loop
echo [%date% %time%] Bot escuchando...
"%PHP%" tools\telegram-sync.php --loop
echo [%date% %time%] El bot se corto. Reiniciando en 3 segundos... (Ctrl+C para salir)
timeout /t 3 >nul
goto loop
