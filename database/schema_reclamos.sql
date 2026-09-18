-- ============================================================
-- Reclamos de clientes (sobre un pedido) + seguimiento (hilo de mensajes)
-- Ejecutar UNA vez sobre britech_v2 (depende de cliente, pedido, usuario).
--
-- El cliente abre un reclamo desde uno de SUS pedidos (asunto + descripción).
-- El staff (admin o vendedor) lo gestiona: cambia el estado y responde. Cliente
-- y staff conversan en un hilo (reclamo_mensaje). La descripción inicial se
-- guarda como el primer mensaje del cliente.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS reclamo (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  numero         VARCHAR(20) NULL,                 -- R-000001 (se setea tras crear)
  cliente_id     INT NOT NULL,
  pedido_id      INT NOT NULL,
  asunto         VARCHAR(150) NOT NULL,
  estado         ENUM('abierto','en_revision','resuelto','rechazado') NOT NULL DEFAULT 'abierto',
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL,
  CONSTRAINT fk_reclamo_cliente FOREIGN KEY (cliente_id) REFERENCES cliente(id),
  CONSTRAINT fk_reclamo_pedido  FOREIGN KEY (pedido_id)  REFERENCES pedido(id),
  INDEX idx_reclamo_estado (estado, creado_en),
  INDEX idx_reclamo_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reclamo_mensaje (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  reclamo_id  INT NOT NULL,
  autor       ENUM('cliente','staff') NOT NULL,
  usuario_id  INT NULL,                            -- staff que respondió (si autor='staff')
  mensaje     TEXT NOT NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_recmsg_reclamo FOREIGN KEY (reclamo_id) REFERENCES reclamo(id) ON DELETE CASCADE,
  CONSTRAINT fk_recmsg_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id),
  INDEX idx_recmsg_reclamo (reclamo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
