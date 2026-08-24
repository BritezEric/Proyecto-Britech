-- ============================================================
-- Tienda online — pedidos + login de clientes
-- Ejecutar UNA vez sobre britech_v2.
--  - El "usuario de la tienda" ES un cliente con contraseña (reusa cliente +
--    su lista de precios). Registro = crea un cliente con password_hash.
--  - La compra genera un PEDIDO (Venta ≠ Pedido). El pedido NO descuenta stock;
--    es una solicitud que el admin gestiona (pendiente → preparando → entregado).
-- ============================================================

USE britech_v2;

-- Contraseña del cliente para la tienda (null = cliente cargado por el admin, sin acceso web)
ALTER TABLE cliente
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER localidad;

CREATE TABLE IF NOT EXISTS pedido (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  numero      VARCHAR(20) NOT NULL UNIQUE,
  cliente_id  INT NOT NULL,
  estado      ENUM('pendiente','preparando','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  total       DECIMAL(12,2) NOT NULL,
  observacion VARCHAR(255) NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pedido_cliente FOREIGN KEY (cliente_id) REFERENCES cliente(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pedido_detalle (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id       INT NOT NULL,
  producto_id     INT NOT NULL,
  cantidad        INT NOT NULL,
  precio_unitario DECIMAL(12,2) NOT NULL,   -- foto del precio al pedir
  subtotal        DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_pdet_pedido   FOREIGN KEY (pedido_id)   REFERENCES pedido(id),
  CONSTRAINT fk_pdet_producto FOREIGN KEY (producto_id) REFERENCES producto(id)
) ENGINE=InnoDB;
