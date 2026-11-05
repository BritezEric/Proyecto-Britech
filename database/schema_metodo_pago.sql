-- Método de pago elegido en el checkout (transferencia | mercadopago | tarjeta).
-- Se completa al crear el pedido. Ejecutar UNA vez sobre britech_v2.
-- (ADD COLUMN IF NOT EXISTS solo existe en MariaDB; en MySQL 8 da error de sintaxis.)
USE britech_v2;

ALTER TABLE pedido
    ADD COLUMN metodo_pago varchar(20) NOT NULL DEFAULT 'transferencia' AFTER estado_pago;
