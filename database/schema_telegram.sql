-- Vinculación de usuarios staff con Telegram para recibir avisos del sistema.
-- Re-ejecutable: agrega las columnas sólo si faltan (MySQL no tiene ADD COLUMN IF NOT EXISTS).
-- telegram_chat_id = chat del usuario con el bot (destino de los avisos).
-- telegram_codigo  = código temporal que el usuario manda al bot (/start CODIGO) para vincularse.

SET @add_chat := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usuario ADD COLUMN telegram_chat_id VARCHAR(32) NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'telegram_chat_id'
);
PREPARE s1 FROM @add_chat; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @add_cod := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usuario ADD COLUMN telegram_codigo VARCHAR(16) NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'telegram_codigo'
);
PREPARE s2 FROM @add_cod; EXECUTE s2; DEALLOCATE PREPARE s2;
