@echo off
setlocal
title Britech - servidor
REM ============================================================
REM  Britech - arranca el servidor de la app (puerto 8123, o el
REM  siguiente libre) y abre el navegador.
REM  Doble clic para prenderlo. Deja esta ventana ABIERTA
REM  mientras uses la app. Para apagarlo: cerra la ventana.
REM  Requiere MySQL de Laragon prendido.
REM ============================================================
cd /d "%~dp0"

REM --- Buscar PHP: Laragon 8.3, otro PHP de Laragon, XAMPP o el del PATH
set "PHP="
for /f "delims=" %%D in ('dir /b /ad /o-n "C:\laragon\bin\php\php-8.3*" 2^>nul') do if not defined PHP if exist "C:\laragon\bin\php\%%D\php.exe" set "PHP=C:\laragon\bin\php\%%D\php.exe"
for /f "delims=" %%D in ('dir /b /ad /o-n "C:\laragon\bin\php\php-*" 2^>nul') do if not defined PHP if exist "C:\laragon\bin\php\%%D\php.exe" set "PHP=C:\laragon\bin\php\%%D\php.exe"
if not defined PHP if exist "D:\fjj05\xampp\php\php.exe" set "PHP=D:\fjj05\xampp\php\php.exe"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP set "PHP=%%P"
if not defined PHP goto sin_php

"%PHP%" -m | findstr /i /b "pdo_mysql" >nul || goto sin_pdo
if not exist "vendor\autoload.php" goto sin_vendor
if not exist ".env" goto sin_env

REM --- MySQL tiene que estar prendido
set "DB_PUERTO=3306"
for /f "tokens=2 delims==" %%V in ('findstr /b "DB_PORT=" ".env"') do set "DB_PUERTO=%%V"
netstat -an | findstr /r /c:":%DB_PUERTO% .*LISTENING" >nul || goto sin_mysql

REM --- Usar el primer puerto libre desde el 8123
set /a PUERTO=8123
:buscar_puerto
netstat -an | findstr /r /c:":%PUERTO% .*LISTENING" >nul || goto arrancar
set /a PUERTO+=1
if %PUERTO% LEQ 8133 goto buscar_puerto
goto sin_puerto

:arrancar
echo.
echo   Britech corriendo en:
echo   -^> http://127.0.0.1:%PUERTO%/login.html   (staff: admin / vendedor)
echo   -^> http://127.0.0.1:%PUERTO%/tienda.html  (clientes)
echo.
echo   Carpeta: %CD%
echo   PHP:     %PHP%
echo.
echo   (No cierres esta ventana mientras uses la app)
echo.
start "" /b cmd /c "ping -n 3 127.0.0.1 >nul & start microsoft-edge:http://127.0.0.1:%PUERTO%/login.html"
"%PHP%" -S 127.0.0.1:%PUERTO% -t public public\index.php
echo.
echo   El servidor se detuvo.
goto fin

:sin_php
echo.
echo   ERROR: no encontre PHP. Instala Laragon en C:\laragon o agrega PHP al PATH.
goto fin
:sin_pdo
echo.
echo   ERROR: %PHP% no tiene activada la extension pdo_mysql (revisa su php.ini).
goto fin
:sin_vendor
echo.
echo   ERROR: falta la carpeta vendor. Ejecuta "composer install" en esta carpeta.
goto fin
:sin_env
echo.
echo   ERROR: falta el archivo .env. Copia .env.example como .env y completalo.
goto fin
:sin_mysql
echo.
echo   ERROR: MySQL no esta prendido en el puerto %DB_PUERTO%.
echo   Abri Laragon, toca "Iniciar todo" y volve a abrir este archivo.
goto fin
:sin_puerto
echo.
echo   ERROR: los puertos 8123 a 8133 estan ocupados. Cerra otro servidor y proba de nuevo.
goto fin

:fin
echo.
pause
