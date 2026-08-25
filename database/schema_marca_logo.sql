-- Logo de marca (para el carrusel de marcas de la tienda). Ejecutar UNA vez.
USE britech_v2;
ALTER TABLE marca ADD COLUMN imagen VARCHAR(255) NULL;
