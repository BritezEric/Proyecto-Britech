-- ============================================================
-- Britech — Módulo Ventas (POS)
-- Esquema de base de datos + datos de semilla
-- Motor: MySQL / MariaDB (InnoDB, utf8mb4)
--
-- Diseño explicado en: docs/modulos/ventas-modelo-datos.md
-- ============================================================

-- Creamos una base NUEVA para no tocar tus proyectos viejos.
-- Podés renombrar 'britech_v2' si querés.
CREATE DATABASE IF NOT EXISTS britech_v2
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE britech_v2;

-- InnoDB: soporta claves foráneas (FK) y transacciones. Es lo que necesitamos.

-- ============================================================
-- ACCESOS
-- ============================================================

CREATE TABLE rol (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE usuario (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol_id        INT NOT NULL,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_usuario_rol FOREIGN KEY (rol_id) REFERENCES rol(id)
) ENGINE=InnoDB;

-- ============================================================
-- COMERCIAL
-- ============================================================

CREATE TABLE lista_precio (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE cliente (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(150) NOT NULL,
  documento       VARCHAR(20) NULL,
  lista_precio_id INT NOT NULL,               -- qué precios ve (minorista/mayorista)
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cliente_lista FOREIGN KEY (lista_precio_id) REFERENCES lista_precio(id)
) ENGINE=InnoDB;

CREATE TABLE producto (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(50) NULL UNIQUE,
  codigo_barras  VARCHAR(50) NULL UNIQUE,     -- para el scanner
  nombre         VARCHAR(150) NOT NULL,
  es_sobre_pedido TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = se puede vender sin stock
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE precio (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  producto_id     INT NOT NULL,
  lista_precio_id INT NOT NULL,
  precio          DECIMAL(12,2) NOT NULL,     -- dinero: DECIMAL, nunca FLOAT
  CONSTRAINT fk_precio_producto FOREIGN KEY (producto_id) REFERENCES producto(id),
  CONSTRAINT fk_precio_lista FOREIGN KEY (lista_precio_id) REFERENCES lista_precio(id),
  -- un solo precio por producto+lista:
  CONSTRAINT uq_precio_prod_lista UNIQUE (producto_id, lista_precio_id)
) ENGINE=InnoDB;

-- ============================================================
-- INVENTARIO
-- ============================================================

-- Existencia actual (número rápido de consultar). 1 fila por producto.
CREATE TABLE inventario (
  producto_id    INT PRIMARY KEY,
  cantidad       INT NOT NULL DEFAULT 0,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventario_producto FOREIGN KEY (producto_id) REFERENCES producto(id)
) ENGINE=InnoDB;

-- ============================================================
-- VENTA
-- ============================================================

CREATE TABLE tipo_pago (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE venta (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  numero     VARCHAR(20) NOT NULL UNIQUE,     -- número legible de venta
  cliente_id INT NOT NULL,
  usuario_id INT NOT NULL,                    -- el vendedor
  subtotal   DECIMAL(12,2) NOT NULL,
  descuento  DECIMAL(12,2) NOT NULL DEFAULT 0,-- descuento sobre el total
  total      DECIMAL(12,2) NOT NULL,
  estado     ENUM('registrada','anulada') NOT NULL DEFAULT 'registrada',
  creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_venta_cliente FOREIGN KEY (cliente_id) REFERENCES cliente(id),
  CONSTRAINT fk_venta_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB;

CREATE TABLE venta_detalle (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  venta_id        INT NOT NULL,
  producto_id     INT NOT NULL,
  cantidad        INT NOT NULL,
  precio_unitario DECIMAL(12,2) NOT NULL,     -- foto del precio al vender
  descuento_linea DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado          ENUM('normal','sobre_pedido') NOT NULL DEFAULT 'normal',
  subtotal        DECIMAL(12,2) NOT NULL,     -- cantidad*precio_unitario - descuento_linea
  CONSTRAINT fk_detalle_venta FOREIGN KEY (venta_id) REFERENCES venta(id),
  CONSTRAINT fk_detalle_producto FOREIGN KEY (producto_id) REFERENCES producto(id)
) ENGINE=InnoDB;

CREATE TABLE pago (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  venta_id     INT NOT NULL,
  tipo_pago_id INT NOT NULL,
  monto        DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_pago_venta FOREIGN KEY (venta_id) REFERENCES venta(id),
  CONSTRAINT fk_pago_tipo FOREIGN KEY (tipo_pago_id) REFERENCES tipo_pago(id)
) ENGINE=InnoDB;

CREATE TABLE comprobante (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  venta_id        INT NOT NULL UNIQUE,        -- 1 comprobante por venta
  tipo            ENUM('ticket_interno','factura_a','factura_b','factura_c')
                    NOT NULL DEFAULT 'ticket_interno',
  numero          VARCHAR(30) NOT NULL,
  punto_venta     VARCHAR(10) NULL,           -- AFIP (más adelante)
  cae             VARCHAR(20) NULL,           -- AFIP (más adelante)
  cae_vencimiento DATE NULL,                  -- AFIP (más adelante)
  fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comprobante_venta FOREIGN KEY (venta_id) REFERENCES venta(id)
) ENGINE=InnoDB;

CREATE TABLE venta_anulacion (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  venta_id   INT NOT NULL UNIQUE,             -- qué venta se anuló
  usuario_id INT NOT NULL,                    -- el admin que anuló
  motivo     VARCHAR(255) NOT NULL,           -- obligatorio
  creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_anulacion_venta FOREIGN KEY (venta_id) REFERENCES venta(id),
  CONSTRAINT fk_anulacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
) ENGINE=InnoDB;

-- Historial de stock (libro mayor). Va al final porque referencia a venta.
CREATE TABLE movimiento_inventario (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  tipo        ENUM('ingreso','egreso','ajuste') NOT NULL,
  cantidad    INT NOT NULL,                   -- magnitud (positiva)
  motivo      VARCHAR(150) NULL,
  venta_id    INT NULL,                       -- de qué venta salió (si aplica)
  usuario_id  INT NOT NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_movinv_producto FOREIGN KEY (producto_id) REFERENCES producto(id),
  CONSTRAINT fk_movinv_venta FOREIGN KEY (venta_id) REFERENCES venta(id),
  CONSTRAINT fk_movinv_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id),
  INDEX idx_movinv_producto (producto_id)     -- buscamos movimientos por producto
) ENGINE=InnoDB;

-- ============================================================
-- DATOS DE SEMILLA (para poder probar)
-- ============================================================

INSERT INTO rol (nombre) VALUES ('admin'), ('vendedor');

-- Usuario admin. La contraseña se setea correctamente cuando construyamos Auth
-- (con password_hash() en PHP). Por ahora queda un valor que NO permite login.
INSERT INTO usuario (nombre, email, password_hash, rol_id)
VALUES ('Administrador', 'admin@britech.local', 'PENDIENTE_SETEAR_EN_AUTH', 1);

INSERT INTO lista_precio (nombre) VALUES ('Minorista'), ('Mayorista');

-- Cliente por defecto: Consumidor Final (usa lista Minorista = id 1)
INSERT INTO cliente (nombre, lista_precio_id) VALUES ('Consumidor Final', 1);
-- Clientes de prueba
INSERT INTO cliente (nombre, lista_precio_id) VALUES
  ('Kiosco Norte (Mayorista)', 2),
  ('Juan Perez', 1);

INSERT INTO tipo_pago (nombre) VALUES ('Efectivo'), ('Transferencia');

-- Productos de prueba
INSERT INTO producto (sku, codigo_barras, nombre, es_sobre_pedido) VALUES
  ('SKU-001', '7791234567890', 'Auricular Bluetooth', 0),
  ('SKU-002', '7790987654321', 'Cargador USB-C 20W', 0),
  ('SKU-003', '7795555555555', 'Notebook 15 (sobre pedido)', 1);  -- sin stock, se vende sobre pedido

-- Precios (minorista = lista 1, mayorista = lista 2)
INSERT INTO precio (producto_id, lista_precio_id, precio) VALUES
  (1, 1, 15000.00), (1, 2, 12000.00),
  (2, 1,  8000.00), (2, 2,  6500.00),
  (3, 1, 900000.00),(3, 2, 850000.00);

-- Inventario inicial (el producto 3 es sobre pedido: queda en 0)
INSERT INTO inventario (producto_id, cantidad) VALUES
  (1, 20),
  (2, 50),
  (3, 0);
