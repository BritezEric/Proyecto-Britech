-- ============================================================
-- Britech: instala la base completa en un solo paso.
-- Ejecutar desde la carpeta raíz del proyecto:
--   mysql -u root < database/instalar.sql
-- Crea la base britech_v2 con el usuario de prueba admin@britech.local / admin1234.
-- ============================================================

SOURCE database/schema_ventas.sql;
SOURCE database/schema_auth.sql;
SOURCE database/schema_datos_maestros.sql;
SOURCE database/schema_min_mayorista.sql;
SOURCE database/schema_imagenes.sql;
SOURCE database/schema_tienda.sql;
SOURCE database/schema_pagos.sql;
SOURCE database/schema_envios.sql;
SOURCE database/schema_envios_datos.sql;
SOURCE database/schema_favoritos.sql;
SOURCE database/schema_mayorista.sql;
SOURCE database/schema_gastos.sql;
SOURCE database/schema_empleados.sql;
SOURCE database/schema_bloques.sql;
SOURCE database/schema_marca_logo.sql;
SOURCE database/schema_metodo_pago.sql;
SOURCE database/schema_moto_barrios.sql;
SOURCE database/schema_envio_venta.sql;
SOURCE database/schema_notificaciones.sql;
SOURCE database/schema_cliente_perfil.sql;
SOURCE database/schema_reposicion.sql;
SOURCE database/schema_reposicion_pedidos.sql;
SOURCE database/schema_compras.sql;
SOURCE database/schema_caja.sql;
SOURCE database/schema_reclamos.sql;
SOURCE database/schema_mercadopago.sql;
SOURCE database/schema_notificaciones_v2.sql;
SOURCE database/schema_tracking.sql;
SOURCE database/schema_auditoria.sql;
SOURCE database/schema_cliente_fiscal.sql;
