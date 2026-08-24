-- ============================================================
-- Envíos de la tienda online
-- Ejecutar UNA vez sobre britech_v2.
--  - empresa_envio: medios de envío con su costo base (tabla maestra, con ABM).
--  - envio: el envío de un pedido (dirección, costo, estado, seguimiento).
-- El costo del envío se suma al total del pedido en el checkout.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS empresa_envio (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(120) NOT NULL,
  costo_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS envio (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id        INT NOT NULL UNIQUE,
  empresa_envio_id INT NULL,
  direccion        VARCHAR(200) NOT NULL,
  localidad        VARCHAR(100) NULL,
  costo            DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado           ENUM('pendiente','despachado','en_camino','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  tracking         VARCHAR(80) NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_envio_pedido  FOREIGN KEY (pedido_id)        REFERENCES pedido(id),
  CONSTRAINT fk_envio_empresa FOREIGN KEY (empresa_envio_id) REFERENCES empresa_envio(id)
) ENGINE=InnoDB;

INSERT INTO empresa_envio (nombre, costo_base) VALUES
  ('Retiro en local', 0),
  ('Correo Argentino', 3500),
  ('Andreani', 4800),
  ('OCA', 5200);
