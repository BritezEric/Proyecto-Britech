-- ============================================================
-- Notificaciones v2: por destinatario (fan-out) + nivel + cliente.
-- Reemplaza la tabla global anterior. Ejecutar UNA vez sobre britech_v2.
--  - Cada fila es un aviso PARA UNA persona: usuario_id (staff) O cliente_id.
--  - Así "leída" es POR persona, y se puede avisar a staff, a un rol, a un
--    usuario puntual o a un cliente.
--  - nivel: colorea el aviso (info/exito/alerta/error).
-- NOTA: se recrea la tabla; se pierden las notificaciones viejas (eran globales).
-- ============================================================

USE britech_v2;

DROP TABLE IF EXISTS notificacion;

CREATE TABLE notificacion (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,                       -- destinatario staff (admin/vendedor)
  cliente_id INT NULL,                       -- destinatario cliente (tienda)
  tipo       VARCHAR(30)  NOT NULL,
  nivel      ENUM('info','exito','alerta','error') NOT NULL DEFAULT 'info',
  titulo     VARCHAR(200) NOT NULL,
  ir         VARCHAR(40)  NULL,              -- sección/vista destino al tocar el aviso
  ref_id     INT NULL,                       -- id del pedido/reclamo/etc.
  leida      TINYINT(1)   NOT NULL DEFAULT 0,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_usuario (usuario_id, leida),
  INDEX ix_cliente (cliente_id, leida),
  CONSTRAINT fk_noti_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
  CONSTRAINT fk_noti_cliente FOREIGN KEY (cliente_id) REFERENCES cliente(id) ON DELETE CASCADE
) ENGINE=InnoDB;
