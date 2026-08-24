-- ============================================================
-- Imágenes de producto (para la ficha de la tienda)
-- Ejecutar UNA vez sobre britech_v2.
-- Varias imágenes por producto (galería). Se cargan por URL desde el ABM.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS producto_imagen (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  url         VARCHAR(500) NOT NULL,
  orden       INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_img_producto FOREIGN KEY (producto_id) REFERENCES producto(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_img_producto ON producto_imagen (producto_id, orden);
