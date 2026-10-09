-- ============================================================
-- Seguimiento de envíos en tiempo real (estilo Uber).
-- El repartidor (o un TrackingTag) emite su posición GPS por HTTP; el cliente y
-- el admin la ven en un mapa. Es agnóstico del hardware: cualquier emisor que
-- haga POST con lat/long + token sirve (celular PWA, ESP32+GPS, tracker comercial).
-- Ejecutar UNA vez sobre britech_v2 (después de schema_moto_barrios.sql).
-- ============================================================

USE britech_v2;

-- El repartidor gana: un token para autenticar el emisor y su última posición.
ALTER TABLE repartidor
  ADD COLUMN token      VARCHAR(40) NULL UNIQUE AFTER telefono,
  ADD COLUMN ult_lat    DECIMAL(10,7) NULL,
  ADD COLUMN ult_lng    DECIMAL(10,7) NULL,
  ADD COLUMN ult_pos_en DATETIME NULL,
  ADD COLUMN en_linea   TINYINT(1) NOT NULL DEFAULT 0;

-- Token aleatorio para los repartidores que ya existen (el emisor lo usa en la URL).
UPDATE repartidor SET token = LOWER(HEX(RANDOM_BYTES(16))) WHERE token IS NULL;

-- Coordenadas de destino del envío (para calcular distancia y ETA hasta la entrega).
ALTER TABLE envio
  ADD COLUMN dest_lat DECIMAL(10,7) NULL,
  ADD COLUMN dest_lng DECIMAL(10,7) NULL;

-- Historial de posiciones (rastro / recorrido). Liviano: se puede purgar.
CREATE TABLE IF NOT EXISTS repartidor_posicion (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  repartidor_id INT NOT NULL,
  lat           DECIMAL(10,7) NOT NULL,
  lng           DECIMAL(10,7) NOT NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_rep_fecha (repartidor_id, creado_en),
  CONSTRAINT fk_pos_repartidor FOREIGN KEY (repartidor_id) REFERENCES repartidor(id) ON DELETE CASCADE
) ENGINE=InnoDB;
