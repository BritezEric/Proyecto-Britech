-- ============================================================
-- Módulo de Entrada de Datos — tablas maestras
-- Ejecutar UNA vez sobre britech_v2 (extiende el schema de Ventas).
-- Agrega: categoria, marca, proveedor + campos de contacto en cliente
-- y descripción/relaciones maestras en producto.
-- ============================================================

USE britech_v2;

-- ---- Tablas maestras nuevas ----

CREATE TABLE IF NOT EXISTS categoria (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(100) NOT NULL UNIQUE,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marca (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(100) NOT NULL UNIQUE,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS proveedor (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(150) NOT NULL,
  cuit      VARCHAR(20)  NULL,
  email     VARCHAR(150) NULL,
  telefono  VARCHAR(30)  NULL,
  direccion VARCHAR(200) NULL,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---- Extensiones a tablas existentes ----
-- (ADD COLUMN no soporta IF NOT EXISTS en MySQL 8: ejecutar una sola vez)

ALTER TABLE cliente
  ADD COLUMN email     VARCHAR(150) NULL AFTER documento,
  ADD COLUMN telefono  VARCHAR(30)  NULL AFTER email,
  ADD COLUMN direccion VARCHAR(200) NULL AFTER telefono,
  ADD COLUMN localidad VARCHAR(100) NULL AFTER direccion;

ALTER TABLE producto
  ADD COLUMN descripcion  VARCHAR(500) NULL AFTER nombre,
  ADD COLUMN categoria_id INT NULL AFTER descripcion,
  ADD COLUMN marca_id     INT NULL AFTER categoria_id,
  ADD COLUMN proveedor_id INT NULL AFTER marca_id,
  ADD CONSTRAINT fk_producto_categoria FOREIGN KEY (categoria_id) REFERENCES categoria(id),
  ADD CONSTRAINT fk_producto_marca     FOREIGN KEY (marca_id)     REFERENCES marca(id),
  ADD CONSTRAINT fk_producto_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedor(id);

-- ---- Semilla para la demo ----

INSERT INTO categoria (nombre) VALUES
  ('Electrónica'), ('Accesorios'), ('Audio'), ('Hogar'), ('Papelería');

INSERT INTO marca (nombre) VALUES
  ('Genérica'), ('Samsung'), ('Logitech'), ('Philips'), ('Noga');

INSERT INTO proveedor (nombre, cuit, email, telefono, direccion) VALUES
  ('Distribuidora Central', '30-71234567-9', 'ventas@central.com.ar', '011-4555-1234', 'Av. Corrientes 1234, CABA'),
  ('TecnoMayorista SA',     '30-70999888-1', 'pedidos@tecnomay.com',  '011-4777-9900', 'San Martín 500, CABA'),
  ('Importadora del Sur',   '30-71555222-4', 'info@impsur.com',        '0221-15-6789',  'Calle 7 456, La Plata');

-- Vincular los productos que ya existen (para que la demo muestre datos)
UPDATE producto SET categoria_id = 3, marca_id = 3, proveedor_id = 2 WHERE nombre LIKE '%Auricular%';
UPDATE producto SET categoria_id = 1, marca_id = 1, proveedor_id = 1 WHERE nombre LIKE '%Cargador%';
UPDATE producto
  SET categoria_id = COALESCE(categoria_id, 2),
      marca_id     = COALESCE(marca_id, 1),
      proveedor_id = COALESCE(proveedor_id, 1)
  WHERE categoria_id IS NULL;

-- Datos de contacto de ejemplo para el cliente existente
UPDATE cliente
  SET email = 'juan.perez@example.com', telefono = '011-15-2233-4455',
      direccion = 'Belgrano 850', localidad = 'CABA'
  WHERE nombre LIKE '%Juan%';
