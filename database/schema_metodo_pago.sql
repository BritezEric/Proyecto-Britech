-- Método de pago elegido en el checkout (transferencia | mercadopago | tarjeta).
-- Se completa al crear el pedido. Idempotente para instalaciones existentes.
ALTER TABLE pedido
    ADD COLUMN IF NOT EXISTS metodo_pago varchar(20) NOT NULL DEFAULT 'transferencia' AFTER estado_pago;
