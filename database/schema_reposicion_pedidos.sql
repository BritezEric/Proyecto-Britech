-- ============================================================
-- Reposición / Historial de pedidos a proveedor
-- Ejecutar UNA vez sobre britech_v2 (depende de producto y proveedor).
-- Guarda cada pedido enviado por WhatsApp (una fila por producto pedido)
-- para NO volver a avisar un producto ya pedido hace poco: la pantalla de
-- Reposición marca los pedidos recientes y arranca su cantidad en 0.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS reposicion_pedido (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  producto_id  INT NOT NULL,
  proveedor_id INT NULL,
  cantidad     INT NOT NULL,
  creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_repo_pedido_producto  FOREIGN KEY (producto_id)  REFERENCES producto(id)  ON DELETE CASCADE,
  CONSTRAINT fk_repo_pedido_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedor(id) ON DELETE SET NULL,
  INDEX idx_repo_pedido_prod (producto_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
