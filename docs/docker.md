# Docker — correr Britech en contenedores

> Estado: **configuración lista**, pendiente de instalar Docker Desktop para probarla.

## Qué es y para qué
Docker empaqueta el proyecto y sus dependencias (PHP, Apache, MySQL) en
**contenedores** aislados, para que corra igual en cualquier PC. No cambia el
código de Britech; cambia **cómo se corre**.

- **Imagen:** la receta de un contenedor.
- **Contenedor:** una imagen en ejecución.
- **`Dockerfile`:** construye la imagen de la app (PHP 8.3 + Apache + pdo_mysql).
- **`docker-compose.yml`:** levanta los 2 contenedores juntos (app + base).

## Los 2 contenedores
| Contenedor | Qué es | Puerto en tu PC |
|---|---|---|
| `app` | PHP 8.3 + Apache (sirve `public/`) | http://localhost:8080 |
| `db`  | MySQL 8.4 (base `britech_v2`) | 3307 |

Se comunican por red interna: la app usa `DB_HOST=db` (el nombre del contenedor
de la base). El schema `database/schema_ventas.sql` se importa solo la primera vez.

## Requisito previo
Instalar **Docker Desktop** para Windows: https://www.docker.com/products/docker-desktop/
(Necesita WSL2; el instalador guía el proceso y pide reiniciar.)

## Comandos
```
docker compose up --build     # construye y levanta todo (primera vez)
docker compose up             # levantar (siguientes veces)
docker compose down           # apagar
docker compose down -v        # apagar y BORRAR los datos de la base
docker compose logs -f app    # ver logs de la app
```
Luego abrir: http://localhost:8080/pos.html

## Cómo funciona junto al .env
- `config/config.php` lee primero las variables del **entorno** (las que pone
  docker-compose) y, si no están, cae al **`.env`** (Laragon).
- Por eso el mismo código corre en Laragon **y** en Docker sin cambios.
