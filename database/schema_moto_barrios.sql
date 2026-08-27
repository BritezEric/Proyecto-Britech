-- ============================================================
-- Moto Express: envío local por BARRIO (precio fijo) + REPARTIDORES.
-- Ejecutar UNA vez sobre britech_v2 (después de schema_envios_datos.sql).
--
--  - barrio: barrios que cubre el moto, cada uno con su costo fijo. Ese costo
--    es lo que paga el cliente Y lo que cobra el repartidor por ese envío.
--  - repartidor: motoquero/delivery (no es usuario del sistema, no tiene login).
--  - empresa_envio.es_moto: marca al medio "Moto Express" → en el checkout pide
--    SOLO el barrio (no la dirección completa).
--  - envio.barrio_id / repartidor_id: barrio elegido en el checkout y el
--    repartidor que el admin asigna para que se haga cargo de la entrega.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS barrio (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(120) NOT NULL,
  costo     DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS repartidor (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(120) NOT NULL,
  telefono  VARCHAR(30) NULL,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Barrios iniciales que cubre el moto.
INSERT INTO barrio (nombre, costo) VALUES
  ('Circuito 5', 5000),
  ('La Nueva Formosa', 5000),
  ('Centro de la ciudad', 2500);

-- Moto Express usa barrios (checkout pide solo el barrio, no dirección completa).
ALTER TABLE empresa_envio
  ADD COLUMN es_moto TINYINT(1) NOT NULL DEFAULT 0 AFTER es_retiro;
UPDATE empresa_envio SET es_moto = 1 WHERE nombre LIKE 'Moto Express%';

-- Barrio elegido en el checkout + repartidor asignado por el admin.
ALTER TABLE envio
  ADD COLUMN barrio_id     INT NULL AFTER empresa_envio_id,
  ADD COLUMN repartidor_id INT NULL AFTER barrio_id,
  ADD CONSTRAINT fk_envio_barrio     FOREIGN KEY (barrio_id)     REFERENCES barrio(id),
  ADD CONSTRAINT fk_envio_repartidor FOREIGN KEY (repartidor_id) REFERENCES repartidor(id);
