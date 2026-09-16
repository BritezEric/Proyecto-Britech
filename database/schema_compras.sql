-- ============================================================
-- Módulo de Procesos · Compras (orden de compra + recepción)
-- Ejecutar UNA vez sobre britech_v2 (depende de producto, proveedor, usuario).
--
-- Flujo: se crea una ORDEN DE COMPRA a un proveedor (a mano o desde Reposición)
-- con sus líneas (producto, cantidad, costo). Cuando llega la mercadería se
-- REGISTRA LA RECEPCIÓN (total o parcial): suma stock, deja movimiento de
-- inventario (tipo 'ingreso') y avanza el estado de la orden.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS orden_compra (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  numero         VARCHAR(20) NULL,                 -- OC-000001 (se setea tras crear)
  proveedor_id   INT NULL,
  estado         ENUM('enviada','parcial','recibida','anulada') NOT NULL DEFAULT 'enviada',
  total_estimado DECIMAL(12,2) NOT NULL DEFAULT 0, -- suma cantidad * costo_unitario
  observacion    VARCHAR(250) NULL,
  usuario_id     INT NOT NULL,                     -- quién la creó
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recibido_en    DATETIME NULL,                    -- cuándo se completó la recepción
  CONSTRAINT fk_oc_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedor(id) ON DELETE SET NULL,
  CONSTRAINT fk_oc_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuario(id),
  INDEX idx_oc_estado (estado, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orden_compra_detalle (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  orden_compra_id   INT NOT NULL,
  producto_id       INT NOT NULL,
  cantidad          INT NOT NULL,                  -- pedido
  costo_unitario    DECIMAL(12,2) NOT NULL DEFAULT 0,
  cantidad_recibida INT NOT NULL DEFAULT 0,        -- acumulado recibido (<= cantidad)
  CONSTRAINT fk_ocd_orden    FOREIGN KEY (orden_compra_id) REFERENCES orden_compra(id) ON DELETE CASCADE,
  CONSTRAINT fk_ocd_producto FOREIGN KEY (producto_id)     REFERENCES producto(id),
  INDEX idx_ocd_orden (orden_compra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
