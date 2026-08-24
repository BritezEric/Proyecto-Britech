-- Gastos del negocio (finanzas): compras a proveedores y gastos generales.
-- Cada gasto tiene un concepto (qué se compró/pagó), un monto, una fecha, un
-- proveedor opcional y una observación. Baja lógica con `activo`.
-- Si el gasto es una compra de stock, `producto_id` + `cantidad` indican qué y
-- cuánto se compró; al crear el gasto se suma ese stock al inventario (con su
-- movimiento). Si van en NULL, es un gasto general (alquiler, servicios, etc.).
CREATE TABLE IF NOT EXISTS gasto (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    fecha        DATE NOT NULL,
    proveedor_id INT NULL,
    producto_id  INT NULL,
    cantidad     INT NULL,
    concepto     VARCHAR(200) NOT NULL,
    monto        DECIMAL(12,2) NOT NULL,
    observacion  VARCHAR(500) NULL,
    activo       TINYINT(1) NOT NULL DEFAULT 1,
    creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gasto_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedor(id),
    CONSTRAINT fk_gasto_producto  FOREIGN KEY (producto_id)  REFERENCES producto(id),
    INDEX idx_gasto_fecha (fecha),
    INDEX idx_gasto_proveedor (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
