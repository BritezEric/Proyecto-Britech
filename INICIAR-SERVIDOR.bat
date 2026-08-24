@echo off
REM ============================================================
REM  Britech - arranca el servidor de la app en el puerto 8123.
REM  Doble clic para prenderlo. Deja esta ventana ABIERTA
REM  mientras uses la app. Para apagarlo: cerra la ventana.
REM  Requiere MySQL de Laragon prendido.
REM ============================================================
cd /d "%~dp0"
echo.
echo   Britech corriendo en:
echo   -^> http://127.0.0.1:8123/login.html   (staff: admin / vendedor)
echo   -^> http://127.0.0.1:8123/tienda.html  (clientes)
echo.
echo   (No cierres esta ventana mientras uses la app)
echo.
"C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe" -S 127.0.0.1:8123 -t public public\index.php
pause
