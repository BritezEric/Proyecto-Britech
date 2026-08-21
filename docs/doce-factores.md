# Britech y los 12 factores (Twelve-Factor App)

> Metodología: https://12factor.net/ — guía para apps web mantenibles y portables.
> Estado de Britech frente a cada factor.

| # | Factor | Qué pide | Estado en Britech |
|---|--------|----------|-------------------|
| I | **Codebase** | Un solo código en control de versiones | 🟡 → **Git** (a inicializar ahora) |
| II | **Dependencies** | Declarar/aislar dependencias | ✅ Composer (`composer.json`/`.lock`, `vendor/`) |
| III | **Config** | Config en el entorno, no en el código | ✅ `.env` + `config.php` lee del entorno |
| IV | **Backing services** | La base es un recurso conectable | ✅ DB por config (host/nombre); se cambia sin tocar código |
| V | **Build, release, run** | Separar construir y ejecutar | 🟡 Docker separa build (imagen) de run; sin release formal |
| VI | **Processes** | Procesos sin estado | ✅ La app no guarda estado propio; todo va a la base |
| VII | **Port binding** | Exponerse por un puerto | ✅ Apache/`php -S`; Docker publica el 8080 |
| VIII | **Concurrency** | Escalar con más procesos | 🟢 Conceptual; Apache atiende varias peticiones |
| IX | **Disposability** | Arranque/parada rápidos | ✅ Contenedores arrancan y paran rápido |
| X | **Dev/prod parity** | Dev y prod parecidos | ✅ **Docker** iguala versiones de PHP/MySQL en dev y prod |
| XI | **Logs** | Logs como flujo (a stdout) | 🟡 Pendiente: enviar logs a stdout/stderr |
| XII | **Admin processes** | Tareas admin como procesos únicos | ✅ El schema se importa como comando único (initdb / mysql) |

Leyenda: ✅ cumplido · 🟡 parcial / a mejorar · 🟢 conceptual (ok a esta escala)

## Acciones concretas
1. **Factor I — Git:** inicializar control de versiones (además resuelve el
   problema de archivos pisados). ← lo hacemos ahora.
2. **Factor XI — Logs:** cuando agreguemos logging, mandar a stdout/stderr en vez
   de gestionar archivos dentro de la app. (Menor por ahora.)
3. **Factor V — Release:** se formaliza más adelante con Docker (build → run).

## Lo que ya hacíamos bien
Composer (II), `.env` (III), base como recurso (IV), procesos sin estado (VI),
port binding (VII), y sobre todo **Docker para dev/prod parity (X)**. O sea, la
arquitectura que elegimos ya iba en la dirección de los 12 factores.
