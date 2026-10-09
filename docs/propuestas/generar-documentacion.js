const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType, LevelFormat,
  Table, TableRow, TableCell, WidthType, BorderStyle, ShadingType: ST,
  Header, Footer, PageNumber, TableOfContents, PageBreak,
} = require('docx');
const fs = require('fs');

const PURPLE = '4C1D95', PURPLE2 = '6D28D9', PURPLE3 = '7C3AED', GREY = '555555', LINE = 'D4D4D8';
const CONTENT_W = 9020;

const P = (text, o = {}) => new Paragraph({
  spacing: { after: o.after ?? 120, before: o.before ?? 0, line: 276 }, alignment: o.align, indent: o.indent,
  children: (Array.isArray(text) ? text : [{ t: text }]).map((x) =>
    typeof x === 'string' ? new TextRun({ text: x }) :
    new TextRun({ text: x.t, bold: x.b, italics: x.i, color: x.c })),
});
const H1 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_1, spacing: { before: 320, after: 140 },
  children: [new TextRun({ text: t, bold: true, color: PURPLE, size: 30 })] });
const H2 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_2, spacing: { before: 240, after: 100 },
  children: [new TextRun({ text: t, bold: true, color: PURPLE2, size: 25 })] });
const H3 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_3, spacing: { before: 150, after: 70 },
  children: [new TextRun({ text: t, bold: true, color: PURPLE3, size: 22 })] });
const BUL = (t) => new Paragraph({ numbering: { reference: 'bul', level: 0 }, spacing: { after: 54, line: 270 },
  children: (Array.isArray(t) ? t : [{ t }]).map((x) => new TextRun(typeof x === 'string' ? { text: x } : { text: x.t, bold: x.b })) });
const PB = () => new Paragraph({ spacing: { after: 0 }, children: [new PageBreak()] });
const SP = (h = 80) => new Paragraph({ spacing: { after: h }, children: [] });

function cell(text, { w, head = false, align } = {}) {
  const runs = (Array.isArray(text) ? text : [text]).map((t) =>
    new TextRun({ text: String(t), bold: head, color: head ? 'FFFFFF' : '222222', size: 19 }));
  return new TableCell({
    width: { size: w, type: WidthType.DXA },
    shading: head ? { type: ST.CLEAR, fill: PURPLE2, color: 'auto' } : undefined,
    margins: { top: 60, bottom: 60, left: 90, right: 90 },
    children: [new Paragraph({ alignment: align, spacing: { after: 0, line: 262 }, children: runs })],
  });
}
function table(cols, rows) {
  const widths = cols.map((c) => c.w);
  const header = new TableRow({ tableHeader: true, children: cols.map((c) => cell(c.h, { w: c.w, head: true, align: c.align })) });
  const body = rows.map((r) => new TableRow({ children: r.map((v, i) => cell(v, { w: widths[i], align: cols[i].align })) }));
  return new Table({
    columnWidths: widths, width: { size: CONTENT_W, type: WidthType.DXA },
    borders: ['top', 'bottom', 'left', 'right', 'insideHorizontal', 'insideVertical'].reduce((a, k) => {
      a[k] = { style: BorderStyle.SINGLE, size: 4, color: LINE }; return a; }, {}),
    rows: [header, ...body],
  });
}

const c = [];

// ---------- Portada ----------
c.push(
  new Paragraph({ spacing: { before: 1500, after: 0 }, alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: 'BRITECH', bold: true, color: PURPLE, size: 76 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 520 },
    children: [new TextRun({ text: 'Plataforma POS · Tienda Online · Panel de Administración', color: GREY, size: 22 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 120 },
    children: [new TextRun({ text: 'Documentación del Proyecto', bold: true, size: 44 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 760 },
    children: [new TextRun({ text: 'Descripción técnica y funcional del sistema', italics: true, color: GREY, size: 24 })] }),
);
c.push(table(
  [{ h: 'Campo', w: 3000 }, { h: 'Detalle', w: 6020 }],
  [
    ['Presentado a', 'Prof. Alan Beck'],
    ['Materia', 'Prácticas Profesionalizantes'],
    ['Proyecto', 'Britech — plataforma de gestión y venta'],
    ['Equipo', 'Eric (admin/backend) · Tobi (controllers/router) · Franco (frontend tienda)'],
    ['Stack', 'PHP 8.1+ · MySQL 8.4 · PDO · JavaScript (vanilla)'],
    ['Base de datos', 'britech_v2 (27 scripts SQL, 3FN, InnoDB)'],
    ['Fecha', 'Octubre de 2026'],
    ['Versión', '1.0'],
  ],
));
c.push(PB());

// ---------- Índice ----------
c.push(H1('Índice'));
c.push(new TableOfContents('Contenido', { hyperlink: true, headingStyleRange: '1-2' }));
c.push(PB());

// ---------- 1. Introducción ----------
c.push(H1('1. Introducción'));
c.push(P('Britech es una plataforma web para gestionar y vender productos tecnológicos, tanto en venta física mediante un punto de venta (POS) como a través de una tienda online minorista y mayorista. Está pensada para un emprendimiento real y, al mismo tiempo, como proyecto de aprendizaje, por lo que prioriza en este orden: que funcione, que sea seguro, que sea fácil de entender, mantenible y escalable.'));
c.push(P('El sistema integra tres frentes sobre una única base de datos: el POS para el mostrador, la tienda online para la venta a distancia, y un panel de administración donde se gestionan productos, clientes, finanzas, envíos, reposición, reclamos y notificaciones.'));
c.push(H3('Actores del sistema'));
c.push(table(
  [{ h: 'Actor', w: 2200 }, { h: 'Descripción', w: 6820 }],
  [
    ['Administrador', 'Acceso completo. Gestiona todo, anula ventas, ve reportes y configura el sistema.'],
    ['Vendedor', 'Usa el POS y la gestión de pedidos/envíos. Sin acceso a lo administrativo sensible.'],
    ['Cliente', 'Compra en la tienda. Minorista por defecto, o mayorista (previa aprobación).'],
  ],
));
c.push(P([{ t: 'Nota importante: ', b: true }, { t: 'usuario (quien inicia sesión en el panel/POS) y cliente (la entidad comercial de la tienda) son conceptos distintos y viven en tablas separadas, con sesiones separadas.' }]));
c.push(PB());

// ---------- 2. Objetivos y alcance ----------
c.push(H1('2. Objetivos y alcance'));
c.push(H3('Objetivo general'));
c.push(P('Brindar una herramienta que permita registrar ventas con stock real, manejar precios por lista (minorista/mayorista), cobrar por distintos medios, gestionar pedidos online con envío y seguimiento, y ofrecer al administrador información clara para tomar decisiones. El éxito se define como una venta cargada en segundos, sin errores de stock ni de dinero, y un sistema que un vendedor nuevo entiende sin manual.'));
c.push(H3('Alcance funcional'));
c.push(P('El proyecto se organiza según los cinco módulos de la materia, más una serie de funcionalidades adicionales incorporadas durante el desarrollo:'));
c.push(BUL('Módulo 1 — Seguridad: usuarios, login, perfiles, roles, sesiones y hash de contraseñas.'));
c.push(BUL('Módulo 2 — Tablas Maestras: ABMs de marcas, categorías, proveedores y barrios.'));
c.push(BUL('Módulo 3 — Entrada de Datos: alta de clientes, productos, stock y proveedores con búsquedas y filtros.'));
c.push(BUL('Módulo 4 — Procesos: ventas (POS), compras, caja del vendedor y notificaciones.'));
c.push(BUL('Módulo 5 — Salidas del Sistema: dashboard con reportes y cierre de caja.'));
c.push(BUL('Adicionales: tienda online, pagos con Mercado Pago, reclamos con seguimiento, envíos Moto Express y login con Google.'));
c.push(PB());

// ---------- 3. Stack tecnológico ----------
c.push(H1('3. Stack tecnológico'));
c.push(table(
  [{ h: 'Categoría', w: 2600 }, { h: 'Herramienta', w: 6420 }],
  [
    ['Lenguaje / Base de datos', 'PHP 8.1+ · MySQL 8.4 · PDO (sentencias preparadas)'],
    ['Entorno de desarrollo', 'Laragon (PHP + MySQL + servidor de desarrollo)'],
    ['Dependencias', 'Composer con autoload PSR-4'],
    ['Configuración', 'vlucas/phpdotenv (.env fuera del repositorio)'],
    ['Correo', 'PHPMailer (SMTP)'],
    ['PDF', 'Dompdf (reportes imprimibles)'],
    ['Pagos', 'Mercado Pago (Checkout Pro, API REST)'],
    ['Login social', 'Auth0 (Google) — opcional'],
    ['Frontend', 'HTML + CSS + JavaScript puro (sin framework)'],
    ['Comunicación', 'AJAX (fetch) entre frontend y backend, intercambiando JSON'],
    ['Infraestructura', 'Docker (contenedores PHP + MySQL) — configurado'],
  ],
));
c.push(PB());

// ---------- 4. Arquitectura ----------
c.push(H1('4. Arquitectura'));
c.push(P('Britech está construido con PHP puro y una arquitectura MVC propia por capas, sin framework ni ORM, con el fin de comprender los mecanismos de base. El backend y el frontend están separados: el backend expone una API que devuelve JSON, y el frontend (HTML/CSS/JS) la consume mediante fetch.'));
c.push(H3('Flujo por capas'));
c.push(new Paragraph({ spacing: { after: 120 }, children: [new TextRun({
  text: 'Frontend (fetch) → public/index.php (Front Controller) → Router → Controller → Service → Repository (PDO) → MySQL',
  font: 'Consolas', size: 18, color: '333333' })] }));
c.push(H3('Responsabilidades'));
c.push(BUL([{ t: 'Controller: ', b: true }, 'recibe la petición HTTP, valida la entrada y la sesión, y responde JSON. No contiene lógica de negocio.']));
c.push(BUL([{ t: 'Service: ', b: true }, 'concentra las reglas del negocio (por ejemplo, confirmar una venta = validar stock, descontar, registrar y emitir comprobante, todo dentro de una transacción).']));
c.push(BUL([{ t: 'Repository: ', b: true }, 'único lugar con SQL, siempre con PDO y sentencias preparadas.']));
c.push(H3('Patrones de diseño aplicados'));
c.push(BUL('Front Controller (public/index.php, única entrada), Router, Repository (aísla el SQL), Service Layer (aísla la lógica), Singleton (una sola conexión a la base) y MVC como estructura general.'));
c.push(PB());

// ---------- 5. Estructura ----------
c.push(H1('5. Estructura del proyecto'));
c.push(new Paragraph({ spacing: { after: 120 }, children: [
  new TextRun({ text: 'app/', bold: true, font: 'Consolas', size: 18 }),
]}));
c.push(table(
  [{ h: 'Carpeta', w: 2400 }, { h: 'Contenido', w: 6620 }],
  [
    ['app/Controllers', 'Reciben la petición, validan la sesión y responden JSON.'],
    ['app/Services', 'Lógica de negocio y validaciones (precio/stock siempre en el backend).'],
    ['app/Repositories', 'Acceso a datos con PDO preparado (sin SQL concatenado).'],
    ['app/Core', 'Router, Request, Response, Session, Database, Mailer, RateLimit.'],
    ['config', 'Configuración central (lee .env / variables de entorno).'],
    ['database', 'Esquemas SQL (27 scripts) y datos de semilla.'],
    ['docs', 'Documentación por módulo, endpoints y guías.'],
    ['public', 'Frontend (HTML/CSS/JS) + index.php (front controller).'],
    ['routes', 'Definición de las rutas de la API (routes/api.php).'],
  ],
));
c.push(PB());

// ---------- 6. Módulos ----------
c.push(H1('6. Módulos funcionales'));

c.push(H2('6.1. Seguridad (Módulo 1)'));
c.push(P('Gestiona el acceso al sistema. El staff (administradores y vendedores) inicia sesión con email y contraseña, guardada con hash (password_hash). Los clientes de la tienda se registran y activan su cuenta por correo, con recuperación de contraseña.'));
c.push(BUL('Sesiones nativas endurecidas: regeneración de ID, cookies httponly, SameSite Lax y modo estricto.'));
c.push(BUL('Limitación de intentos (rate limiting) en login y registro.'));
c.push(BUL('Roles: administrador y vendedor (un rol por usuario).'));
c.push(BUL('Login con Google mediante Auth0 (opcional) para clientes, con cierre de sesión real.'));
c.push(BUL('Páginas de contraseña con mostrar/ocultar y validación en vivo.'));

c.push(H2('6.2. Tablas Maestras (Módulo 2)'));
c.push(P('ABMs (altas, bajas y modificaciones) de las entidades base del catálogo y la operación: marcas, categorías, proveedores y barrios (para Moto Express), con validaciones y baja lógica (no se borra físicamente). Quedan pendientes del enunciado las tablas de provincias, localidades, tipos de documento y unidades de medida.'));

c.push(H2('6.3. Entrada de Datos (Módulo 3)'));
c.push(P('Alta y mantenimiento de clientes, productos (con precio por lista y stock inicial) y proveedores, con búsqueda, filtros y paginación del lado del servidor. Incluye imágenes de producto y listas de precio minorista/mayorista. El frontend usa un framework de ABM reutilizable guiado por configuración por entidad.'));

c.push(H2('6.4. Procesos (Módulo 4)'));
c.push(H3('Ventas (POS)'));
c.push(P('Venta en el local con búsqueda de productos, precio y stock calculados en el backend, medios de pago, envío opcional en el ticket, descuento automático de stock, y anulación con motivo y reintegro. Todo es transaccional y queda registrado en el historial de movimientos de inventario. La venta exige una caja abierta y se asocia a ella.'));
c.push(H3('Compras'));
c.push(P('Órdenes de compra a proveedores (manuales o generadas desde Reposición) y recepción de mercadería, total o parcial, que suma stock automáticamente. Incluye anulación. Toda la operación es transaccional.'));
c.push(H3('Caja del vendedor'));
c.push(P('Apertura de caja con monto inicial, registro de movimientos (retiros e ingresos) y cierre con arqueo. El sistema calcula el efectivo esperado (apertura + ventas en efectivo − retiros + ingresos) y la diferencia respecto de lo contado. El historial de cajas queda disponible para el administrador.'));
c.push(H3('Notificaciones'));
c.push(P('Sistema de avisos por destinatario: cada notificación se dirige a una persona (staff o cliente), por lo que el estado "leída" es individual. Hay campana en el panel (staff) y en la tienda (cliente), con niveles (info, éxito, alerta, error). Se generan en todos los procesos relevantes —pedido nuevo, pago aprobado, stock bajo, mercadería recibida, cierre de caja, reclamos, etc.— y, en los hitos clave del cliente, se complementan con correo electrónico.'));

c.push(H2('6.5. Salidas del Sistema (Módulo 5)'));
c.push(P('El panel de inicio funciona como dashboard: muestra indicadores (ventas del día y del mes, físicas vs. online, ticket promedio, clientes), gráficos de barras y de torta, ventas por categoría, por vendedor, top de productos, stock bajo y finanzas (ingresos vs. gastos). El cierre de caja cubre la salida de arqueo del turno. Quedan como ampliación los reportes gerenciales avanzados y la auditoría.'));

c.push(H2('6.6. Tienda online'));
c.push(P('Catálogo público con filtros (marca, precio, stock), favoritos, carrito y checkout en dos pasos (datos de entrega → método de pago). Soporta modo minorista y mayorista (con aprobación). El envío puede ser retiro en local, correo o Moto Express por barrio. El cliente hace el seguimiento de su pedido desde "Mis pedidos".'));

c.push(H2('6.7. Pagos'));
c.push(P('Dos formas de pago online: transferencia manual (el cliente ve el alias/CBU, transfiere y sube el comprobante, que el staff aprueba o rechaza) y Mercado Pago (Checkout Pro), que cobra en línea y confirma el pago automáticamente mediante verificación con el servidor de Mercado Pago (webhook y retorno del cliente). El monto lo calcula siempre el backend. En el POS, el pago se registra por medio (efectivo o transferencia) y queda asociado a la caja.'));

c.push(H2('6.8. Reclamos'));
c.push(P('El cliente abre un reclamo sobre uno de sus pedidos y lo sigue por un hilo de conversación tipo chat; el staff lo gestiona desde el panel (responde y cambia el estado: abierto, en revisión, resuelto o rechazado). Cada respuesta del staff genera una notificación y un correo al cliente.'));

c.push(H2('6.9. Envíos y repartidores (Moto Express)'));
c.push(P('Medio de envío local por barrio (precio fijo por zona). Los repartidores se gestionan como ABM y el administrador los deriva a cada envío desde un tablero de "Repartos"; su paga se calcula como la suma de los barrios de sus envíos entregados en el día. Un envío puede colgar de un pedido de la tienda o de una venta del POS.'));
c.push(PB());

// ---------- 7. Modelo de datos ----------
c.push(H1('7. Modelo de datos'));
c.push(P('La base de datos britech_v2 está diseñada en tercera forma normal (3FN), con motor InnoDB e integridad referencial por claves foráneas. Se compone de 27 scripts SQL que se importan en orden (las dependencias entre tablas lo exigen).'));
c.push(H3('Decisiones de modelado destacadas'));
c.push(BUL('Venta ≠ Pedido: son entidades separadas (la venta es del POS y descuenta stock; el pedido es de la tienda y es una solicitud a gestionar).'));
c.push(BUL('Stock como pool único con libro mayor de movimientos (movimiento_inventario) para trazabilidad.'));
c.push(BUL('Dinero en DECIMAL (no FLOAT) y precio congelado en el detalle de la venta.'));
c.push(BUL('Precio por cliente mediante lista_precio_id (minorista/mayorista).'));
c.push(BUL('El pago del pedido online se guarda en columnas del propio pedido (estado_pago, metodo_pago, comprobante_url, mp_payment_id); el pago del POS, en la tabla pago.'));
c.push(BUL('Notificaciones con una fila por destinatario (usuario_id o cliente_id) para que "leída" sea por persona.'));
c.push(PB());

// ---------- 8. API ----------
c.push(H1('8. API (interfaz de programación)'));
c.push(P('El backend expone una API JSON definida en routes/api.php. Las rutas se agrupan por área y se protegen según corresponda: públicas, de cliente (sesión de tienda) o de staff (sesión de panel; algunas sólo para administrador). El mapa completo y actualizado de endpoints está en el documento docs/ENDPOINTS.md. A modo de referencia, las áreas principales son:'));
c.push(table(
  [{ h: 'Área', w: 3000 }, { h: 'Ejemplos de rutas', w: 6020 }],
  [
    ['Autenticación', '/api/login · /api/yo · /api/tienda/login · /api/tienda/registro'],
    ['POS / Ventas', '/api/ventas · /api/ventas/anular · /api/tipos-pago'],
    ['Caja', '/api/caja/abrir · /api/caja/cerrar · /api/admin/cajas'],
    ['Compras', '/api/admin/compras · /api/admin/compras/recibir'],
    ['Pedidos', '/api/tienda/pedidos · /api/admin/pedidos/estado · /api/admin/pedidos/pago'],
    ['Pagos (Mercado Pago)', '/api/tienda/pago/iniciar · /api/tienda/pago/confirmar · /api/tienda/pago/webhook'],
    ['Reclamos', '/api/tienda/reclamos · /api/admin/reclamos/mensaje'],
    ['Notificaciones', '/api/admin/notificaciones · /api/tienda/notificaciones'],
    ['Dashboard', '/api/admin/dashboard · /api/admin/dashboard/serie'],
    ['Tienda (catálogo)', '/api/tienda/catalogo · /api/tienda/producto · /api/tienda/home'],
  ],
));
c.push(PB());

// ---------- 9. Seguridad ----------
c.push(H1('9. Seguridad'));
c.push(P('La seguridad es una línea base transversal a todo el sistema:'));
c.push(BUL('PDO con sentencias preparadas siempre (previene inyección SQL).'));
c.push(BUL('Contraseñas con hash (password_hash), nunca en texto plano.'));
c.push(BUL('Autorización verificada en el backend, no sólo ocultando botones en el frontend.'));
c.push(BUL('Escape de la salida HTML para prevenir XSS.'));
c.push(BUL('Credenciales y secretos en .env, fuera del código y del repositorio (.gitignore).'));
c.push(BUL('Operaciones que tocan datos importantes dentro de transacciones de base de datos.'));
c.push(BUL('Pagos verificados contra el servidor de Mercado Pago; el monto lo fija el backend.'));
c.push(BUL('Registro de acciones sensibles (venta creada, anulada, etc.).'));
c.push(PB());

// ---------- 10. Instalación ----------
c.push(H1('10. Instalación y puesta en marcha'));
c.push(H3('Requisitos'));
c.push(BUL('PHP 8.1 o superior con extensiones pdo_mysql, mbstring y openssl.'));
c.push(BUL('MySQL 8 / MariaDB 10.4+.'));
c.push(BUL('Composer (Laragon incluye todo lo anterior).'));
c.push(H3('Pasos'));
c.push(new Paragraph({ numbering: { reference: 'steps', level: 0 }, spacing: { after: 54 }, children: [new TextRun('Instalar dependencias con composer install.')] }));
c.push(new Paragraph({ numbering: { reference: 'steps', level: 0 }, spacing: { after: 54 }, children: [new TextRun('Importar los 27 scripts SQL de la carpeta database en orden (el primero crea la base britech_v2).')] }));
c.push(new Paragraph({ numbering: { reference: 'steps', level: 0 }, spacing: { after: 54 }, children: [new TextRun('Copiar .env.example a .env y completar credenciales de base de datos, correo y, opcionalmente, Mercado Pago y Auth0.')] }));
c.push(new Paragraph({ numbering: { reference: 'steps', level: 0 }, spacing: { after: 54 }, children: [new TextRun('Levantar el servidor de desarrollo: php -S 127.0.0.1:8123 -t public public/index.php.')] }));
c.push(new Paragraph({ numbering: { reference: 'steps', level: 0 }, spacing: { after: 54 }, children: [new TextRun('Acceder a la tienda, el panel (/admin.html) o el POS (/pos.html).')] }));
c.push(SP(60));
c.push(P([{ t: 'Credenciales de prueba (admin): ', b: true }, { t: 'admin@britech.local / admin1234.' }]));
c.push(P([{ t: 'Prueba automatizada: ', b: true }, { t: 'php tests/smoke.php (verifica autoload, configuración, base de datos, login y reglas de validación).' }]));
c.push(PB());

// ---------- 11. Estado ----------
c.push(H1('11. Estado del proyecto'));
c.push(table(
  [{ h: '#', w: 700, align: AlignmentType.CENTER }, { h: 'Módulo', w: 3600 }, { h: 'Estado', w: 4720 }],
  [
    ['1', 'Seguridad', 'Funcional (login, roles, sesiones, Google, recuperación).'],
    ['2', 'Tablas Maestras', 'Parcial (faltan provincias, localidades, tipos de documento y unidades).'],
    ['3', 'Entrada de Datos', 'Completo (ABM con búsquedas, filtros y paginación).'],
    ['4', 'Procesos', 'Casi completo (ventas, compras, caja y notificaciones).'],
    ['5', 'Salidas del Sistema', 'Iniciado (dashboard y cierre de caja; faltan reportes gerenciales y auditoría).'],
  ],
));
c.push(SP(80));
c.push(H3('Adicionales ya construidos'));
c.push(P('Tienda online completa, pagos con Mercado Pago, reclamos con seguimiento, notificaciones por destinatario (staff y cliente) con correo, dashboard con gráficos, y envíos Moto Express con repartidores.'));
c.push(H3('Qué falta para un producto en producción'));
c.push(BUL('Servir con Apache/nginx + PHP-FPM y HTTPS; APP_DEBUG en false; backups automáticos.'));
c.push(BUL('Completar las tablas maestras del Módulo 2 y los reportes/auditoría del Módulo 5.'));
c.push(BUL('Facturación AFIP y textos legales (términos, privacidad).'));
c.push(PB());

// ---------- 12. Documentación ----------
c.push(H1('12. Documentación complementaria'));
c.push(P('El repositorio incluye documentación viva por módulo y de referencia, que amplía este documento:'));
c.push(table(
  [{ h: 'Documento', w: 3400 }, { h: 'Contenido', w: 5620 }],
  [
    ['SPEC.md', 'Especificación maestra: objetivo, arquitectura, decisiones y convenciones.'],
    ['README.md', 'Guía de instalación, importación de esquemas y puesta en marcha.'],
    ['docs/ESTADO-DEL-PROYECTO.md', 'Estado por módulo y qué falta para producción.'],
    ['docs/ENDPOINTS.md', 'Mapa completo de la API (todas las rutas).'],
    ['docs/modulos/*.md', 'Documentación detallada por módulo (ventas, compras, caja, mercadopago, reclamos, envíos, etc.).'],
    ['docs/exposicion/*.md', 'Guías de defensa del código por integrante y de pagos.'],
    ['DESIGN.md · PRODUCT.md', 'Sistema de diseño, identidad de marca y principios de producto.'],
  ],
));
c.push(SP(100));
c.push(P([{ t: 'Conclusión. ', b: true }, { t: 'Britech es un sistema funcional que cubre de punta a punta el circuito de venta (física y online), con una arquitectura clara por capas, seguridad de base, pagos reales mediante Mercado Pago y un panel de gestión con información para la toma de decisiones. Su documentación acompaña cada módulo, facilitando el mantenimiento y la evolución del proyecto.' }]));

// ========================================================= DOC
const doc = new Document({
  creator: 'Equipo Britech', title: 'Documentación del Proyecto Britech',
  description: 'Documentación técnica y funcional',
  numbering: { config: [
    { reference: 'bul', levels: [{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT,
      style: { paragraph: { indent: { left: 460, hanging: 260 } } } }] },
    { reference: 'steps', levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT,
      style: { paragraph: { indent: { left: 460, hanging: 260 } } } }] },
  ] },
  styles: { default: { document: { run: { font: 'Calibri', size: 22, color: '222222' } } } },
  sections: [{
    properties: { page: { margin: { top: 1440, bottom: 1440, left: 1440, right: 1440 } } },
    headers: { default: new Header({ children: [new Paragraph({
      alignment: AlignmentType.RIGHT, spacing: { after: 0 },
      border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: LINE, space: 6 } },
      children: [new TextRun({ text: 'Britech · Documentación del Proyecto', color: GREY, size: 16 })] })] }) },
    footers: { default: new Footer({ children: [new Paragraph({
      alignment: AlignmentType.CENTER,
      children: [new TextRun({ text: 'Página ', color: GREY, size: 16 }),
        new TextRun({ children: [PageNumber.CURRENT], color: GREY, size: 16 }),
        new TextRun({ text: ' de ', color: GREY, size: 16 }),
        new TextRun({ children: [PageNumber.TOTAL_PAGES], color: GREY, size: 16 })] })] }) },
    children: c,
  }],
});
Packer.toBuffer(doc).then((b) => { fs.writeFileSync('Britech-Documentacion.docx', b); console.log('OK ' + b.length + ' bytes'); });
