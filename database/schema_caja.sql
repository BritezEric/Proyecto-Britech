-- ============================================================
-- Caja del vendedor (apertura, movimientos, cierre / arqueo)
-- Ejecutar UNA vez sobre britech_v2 (depende de usuario y venta).
--
-- Flujo (estricto): el vendedor ABRE la caja con un monto inicial; cada venta
-- del POS queda ligada a esa caja (venta.caja_id); puede registrar RETIROS/
-- INGRESOS de efectivo; al CERRAR cuenta el efectivo real (arqueo) y el sistema
-- calcula lo esperado y la diferencia. El admin ve el historial de cierres.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS caja (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT NOT NULL,                       -- vendedor que la abrió
  monto_apertura  DECIMAL(12,2) NOT NULL DEFAULT 0,
  abierta_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado          ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
  monto_contado   DECIMAL(12,2) NULL,                 -- efectivo real contado al cerrar
  monto_esperado  DECIMAL(12,2) NULL,                 -- apertura + ventas efectivo - retiros + ingresos
  diferencia      DECIMAL(12,2) NULL,                 -- contado - esperado
  observacion     VARCHAR(250) NULL,                  -- nota del cierre
  cerrada_en      DATETIME NULL,
  CONSTRAINT fk_caja_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id),
  INDEX idx_caja_estado (estado, abierta_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS caja_movimiento (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  caja_id     INT NOT NULL,
  tipo        ENUM('retiro','ingreso') NOT NULL,      -- retiro = sacar efectivo; ingreso = poner
  monto       DECIMAL(12,2) NOT NULL,                 -- magnitud (positiva)
  motivo      VARCHAR(200) NULL,
  usuario_id  INT NOT NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cajamov_caja    FOREIGN KEY (caja_id)    REFERENCES caja(id) ON DELETE CASCADE,
  CONSTRAINT fk_cajamov_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id),
  INDEX idx_cajamov_caja (caja_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada venta del POS queda ligada a la caja abierta del vendedor.
ALTER TABLE venta ADD COLUMN caja_id INT NULL AFTER usuario_id;
ALTER TABLE venta ADD CONSTRAINT fk_venta_caja FOREIGN KEY (caja_id) REFERENCES caja(id);
