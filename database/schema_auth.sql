-- ============================================================
-- Britech — Módulo Autenticación
-- Agrega verificación de email y tokens (verificación + reset).
-- ============================================================

USE britech_v2;

-- Marca si el usuario confirmó su correo.
ALTER TABLE usuario
  ADD COLUMN email_verificado TINYINT(1) NOT NULL DEFAULT 0 AFTER activo;

-- Tokens de un solo uso para verificación de email y reset de contraseña.
-- Se guarda el HASH del token, no el token en texto (seguridad).
CREATE TABLE usuario_token (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  tipo       ENUM('verificacion','reset') NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,      -- sha256 en hexadecimal
  expira_en  DATETIME NOT NULL,
  usado      TINYINT(1) NOT NULL DEFAULT 0,
  creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_token_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id),
  INDEX idx_token_usuario (usuario_id)
) ENGINE=InnoDB;

-- El admin que ya existe queda como verificado (para poder entrar).
UPDATE usuario SET email_verificado = 1 WHERE email = 'admin@britech.local';

-- Contraseña de desarrollo del admin: "admin1234" (hash bcrypt).
-- CAMBIAR en producción. Se puede regenerar con:
--   php -r "echo password_hash('otra', PASSWORD_DEFAULT);"
UPDATE usuario
SET password_hash = '$2y$12$VuVlwf5pu8q/./VTwZRRK..98099KeAyA68WYsGDb/1Ex/9Lj.qUi'
WHERE email = 'admin@britech.local';
