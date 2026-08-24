-- ============================================================
-- Favoritos / wishlist de la tienda online
-- Ejecutar UNA vez sobre britech_v2.
-- Un cliente puede marcar productos como favoritos (❤️).
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS favorito (
  cliente_id  INT NOT NULL,
  producto_id INT NOT NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cliente_id, producto_id),
  CONSTRAINT fk_fav_cliente  FOREIGN KEY (cliente_id)  REFERENCES cliente(id)  ON DELETE CASCADE,
  CONSTRAINT fk_fav_producto FOREIGN KEY (producto_id) REFERENCES producto(id) ON DELETE CASCADE
) ENGINE=InnoDB;
