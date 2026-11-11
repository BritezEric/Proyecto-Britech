-- PIN del repartidor para que entre a su app (repartidor.html) e identifique quién es.
-- Guardado hasheado (password_hash). Re-ejecutable: agrega la columna sólo si falta.
SET @add_pin := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE repartidor ADD COLUMN pin VARCHAR(255) NULL',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repartidor' AND COLUMN_NAME = 'pin'
);
PREPARE s FROM @add_pin; EXECUTE s; DEALLOCATE PREPARE s;
