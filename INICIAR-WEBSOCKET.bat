@echo off
setlocal
title Britech - tiempo real (WebSocket)
REM ============================================================
REM  Britech - servidor de tiempo real (WebSocket, bin\websocket.php).
REM  Doble clic para prenderlo y deja esta ventana ABIERTA.
REM  Para apagarlo: Ctrl+C o cerra la ventana.
REM  INICIAR-SERVIDOR.bat ya lo abre solo si WS_SECRET esta en el .env.
REM ============================================================
cd /d "%~dp0"

set "PHP="
for /f "delims=" %%D in ('dir /b /ad /o-n "C:\laragon\bin\php\php-8.3*" 2^>nul') do if not defined PHP if exist "C:\laragon\bin\php\%%D\php.exe" set "PHP=C:\laragon\bin\php\%%D\php.exe"
for /f "delims=" %%D in ('dir /b /ad /o-n "C:\laragon\bin\php\php-*" 2^>nul') do if not defined PHP if exist "C:\laragon\bin\php\%%D\php.exe" set "PHP=C:\laragon\bin\php\%%D\php.exe"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP set "PHP=%%P"
if not defined PHP (
    echo   ERROR: no encontre PHP. Instala Laragon en C:\laragon o agrega PHP al PATH.
    goto fin
)
if not exist "vendor\workerman" (
    echo   ERROR: falta Workerman. Ejecuta "composer install" en esta carpeta.
    goto fin
)
findstr /b /r /c:"WS_SECRET=..*" ".env" >nul || (
    echo   ERROR: falta WS_SECRET en el .env. Ver .env.example.
    goto fin
)

echo.
echo   Britech - tiempo real. No cierres esta ventana mientras uses la app.
echo.
"%PHP%" bin\websocket.php start
echo.
echo   El servidor de tiempo real se detuvo.

:fin
echo.
pause
