-- ============================================================
-- Home modular por bloques (page builder de la tienda)
-- Ejecutar UNA vez sobre britech_v2.
--  bloque        = la home es una lista ORDENADA de bloques.
--  bloque_slide  = slides del hero (imagen, título, botón…).
--  categoria     += imagen + orden (para el carrusel de categorías).
-- El campo config (JSON) guarda los ajustes propios de cada tipo de bloque,
-- así se pueden agregar tipos nuevos sin crear tablas nuevas.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS bloque (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  tipo      VARCHAR(40) NOT NULL,      -- hero | banner | video | carrusel_categorias | carrusel_productos | grid_productos
  titulo    VARCHAR(150) NULL,         -- título de sección (ej. "Smartphones")
  config    JSON NULL,                 -- ajustes según el tipo
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  orden     INT NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bloque_slide (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  bloque_id      INT NOT NULL,
  imagen_desktop VARCHAR(500) NULL,
  imagen_mobile  VARCHAR(500) NULL,
  titulo         VARCHAR(150) NULL,
  subtitulo      VARCHAR(255) NULL,
  boton_texto    VARCHAR(60)  NULL,
  url            VARCHAR(500) NULL,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  orden          INT NOT NULL DEFAULT 0,
  desde          DATE NULL,
  hasta          DATE NULL,
  CONSTRAINT fk_slide_bloque FOREIGN KEY (bloque_id) REFERENCES bloque(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_bloque_orden ON bloque (activo, orden);
CREATE INDEX idx_slide_bloque ON bloque_slide (bloque_id, orden);
