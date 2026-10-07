const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType, LevelFormat,
  Table, TableRow, TableCell, WidthType, BorderStyle, ShadingType, ShadingType: ST,
  Header, Footer, PageNumber, TableOfContents, PageBreak,
} = require('docx');
const fs = require('fs');

const PURPLE = '4C1D95', PURPLE2 = '6D28D9', PURPLE3 = '7C3AED', GREY = '555555', LINE = 'D4D4D8';
const CONTENT_W = 9020;

// ---------- helpers ----------
const P = (text, o = {}) => new Paragraph({
  spacing: { after: o.after ?? 120, before: o.before ?? 0, line: 276 },
  alignment: o.align, indent: o.indent,
  children: (Array.isArray(text) ? text : [{ t: text }]).map((x) =>
    typeof x === 'string' ? new TextRun({ text: x }) :
    new TextRun({ text: x.t, bold: x.b, italics: x.i, color: x.c, size: x.sz })),
});
const H1 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_1, spacing: { before: 320, after: 140 },
  children: [new TextRun({ text: t, bold: true, color: PURPLE, size: 30 })] });
const H2 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_2, spacing: { before: 260, after: 110 },
  children: [new TextRun({ text: t, bold: true, color: PURPLE2, size: 26 })] });
const H3 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_3, spacing: { before: 160, after: 80 },
  children: [new TextRun({ text: t, bold: true, color: PURPLE3, size: 22 })] });
const BUL = (t) => new Paragraph({ numbering: { reference: 'bul', level: 0 }, spacing: { after: 60, line: 272 },
  children: [new TextRun(typeof t === 'string' ? { text: t } : { text: t.t, bold: t.b })] });
const STEP = (t) => new Paragraph({ numbering: { reference: 'steps', level: 0 }, spacing: { after: 70, line: 272 },
  children: [new TextRun({ text: t })] });

function cell(text, { w, head = false, bold = false, align } = {}) {
  const runs = (Array.isArray(text) ? text : [text]).map((t) =>
    new TextRun({ text: String(t), bold: head || bold, color: head ? 'FFFFFF' : '222222', size: 19 }));
  return new TableCell({
    width: { size: w, type: WidthType.DXA },
    shading: head ? { type: ST.CLEAR, fill: PURPLE2, color: 'auto' } : undefined,
    margins: { top: 60, bottom: 60, left: 90, right: 90 },
    children: [new Paragraph({ alignment: align, spacing: { after: 0, line: 264 }, children: runs })],
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
const SPACER = (h = 80) => new Paragraph({ spacing: { after: h }, children: [] });

// ---------- feature block ----------
function feature(code, title, d) {
  const out = [H1(`${code}. ${title}`)];
  out.push(H3('Descripción — qué hará'));
  (Array.isArray(d.descripcion) ? d.descripcion : [d.descripcion]).forEach((t) => out.push(P(t)));
  out.push(H3('Posibilidad de incorporación'));
  (Array.isArray(d.viabilidad) ? d.viabilidad : [d.viabilidad]).forEach((t) => out.push(P(t)));
  out.push(H3('Recursos necesarios'));
  d.recursos.forEach((t) => out.push(BUL(t)));
  out.push(H3('Alcance'));
  out.push(P([{ t: 'Incluye:', b: true }]));
  d.incluye.forEach((t) => out.push(BUL(t)));
  if (d.fuera && d.fuera.length) {
    out.push(P([{ t: 'Queda fuera (fases futuras):', b: true }]));
    d.fuera.forEach((t) => out.push(BUL(t)));
  }
  out.push(H3('Cómo se verá'));
  (Array.isArray(d.verse) ? d.verse : [d.verse]).forEach((t) => out.push(P(t)));
  out.push(H3('Plan de construcción'));
  d.plan.forEach((t) => out.push(STEP(t)));
  out.push(new Paragraph({ spacing: { after: 0 }, children: [new PageBreak()] }));
  return out;
}

// ========================================================= CONTENT
const children = [];

// ---- Portada ----
children.push(
  new Paragraph({ spacing: { before: 1600, after: 0 }, alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: 'BRITECH', bold: true, color: PURPLE, size: 72 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 500 },
    children: [new TextRun({ text: 'Plataforma POS · Tienda Online · Panel de Administración', color: GREY, size: 22 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 120 },
    children: [new TextRun({ text: 'Propuesta de Implementaciones Tecnológicas', bold: true, size: 40 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 700 },
    children: [new TextRun({ text: 'Innovaciones funcionales de incorporación gratuita', italics: true, color: GREY, size: 24 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 60 },
    children: [new TextRun({ text: 'Documento formal de implementación', bold: true, size: 22 })] }),
  new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 700 },
    children: [new TextRun({ text: 'Versión 1.0', color: GREY, size: 20 })] }),
);
children.push(table(
  [{ h: 'Campo', w: 3000 }, { h: 'Detalle', w: 6020 }],
  [
    ['Presentado a', 'Prof. Alan Beck'],
    ['Materia', 'Prácticas Profesionalizantes'],
    ['Proyecto', 'Britech'],
    ['Responsable', 'Eric (equipo de desarrollo)'],
    ['Fecha', 'Octubre de 2026'],
    ['Alcance', '6 propuestas de innovación tecnológica'],
  ],
));
children.push(new Paragraph({ spacing: { after: 0 }, children: [new PageBreak()] }));

// ---- Índice ----
children.push(H1('Índice'));
children.push(new TableOfContents('Contenido', { hyperlink: true, headingStyleRange: '1-2' }));
children.push(new Paragraph({ spacing: { after: 0 }, children: [new PageBreak()] }));

// ---- 1. Introducción ----
children.push(H1('Introducción'));
children.push(P('Britech es una plataforma de gestión y venta desarrollada en el marco de la materia Prácticas Profesionalizantes. Integra tres frentes sobre una misma base de datos: un punto de venta (POS) para el local, una tienda online y un panel de administración (finanzas, envíos, reposición, reclamos y notificaciones). Está construida con PHP 8.1+ y MySQL, bajo una arquitectura por capas (Front Controller → Router → Controller → Service → Repository → PDO), con un frontend en HTML, CSS y JavaScript puro que consume una API JSON. Ya cuenta con cobro online mediante Mercado Pago, un sistema de notificaciones por destinatario (staff y cliente) y envíos por Moto Express.'));
children.push(P('El presente documento propone un conjunto de incorporaciones tecnológicas orientadas a diferenciar el proyecto, mejorar la operación real del negocio y demostrar dominio técnico. Cada propuesta fue seleccionada bajo tres criterios: (1) ser de implementación gratuita, (2) aportar innovación poco frecuente en este tipo de sistemas y (3) ser funcional y aplicable a la operación diaria.'));
children.push(P([{ t: 'Nota sobre infraestructura: ', b: true }, { t: 'el equipo dispone de un servidor propio, lo que habilita el uso de n8n (orquestador de automatizaciones de código abierto) como pieza transversal para varias de estas propuestas, sin costos de licencia.' }]));

// ---- 2. Resumen ejecutivo ----
children.push(H1('Resumen ejecutivo'));
children.push(P('Se proponen seis implementaciones. La siguiente tabla las resume; cada una se desarrolla en detalle en su sección correspondiente (descripción, viabilidad, recursos, alcance, aspecto visual y plan de construcción).'));
children.push(table(
  [{ h: 'Propuesta', w: 2550 }, { h: 'Qué resuelve', w: 4070 }, { h: 'Esfuerzo', w: 1100, align: AlignmentType.CENTER }, { h: 'Costo', w: 1300, align: AlignmentType.CENTER }],
  [
    ['1. Asistente de voz manos libres (POS)', 'Cargar productos y registrar ventas hablando, sin tocar el teclado', 'Alto', 'Gratis'],
    ['2. Pago con QR desde el POS', 'Cobrar en el local con Mercado Pago escaneando un QR', 'Medio', 'Gratis'],
    ['3. Seguimiento del delivery en vivo', 'Ver la moto en un mapa en tiempo real (Formosa) + TrackingTag', 'Medio', 'Gratis*'],
    ['4. Integraciones con WhatsApp (n8n)', 'Resumen diario, link de pago, recibo y pedidos por WhatsApp', 'Medio', 'Gratis'],
    ['5. Auto-etiquetado de productos por foto', 'Sugerir categoría y marca a partir de la imagen (IA)', 'Medio', 'Gratis'],
    ['6. Enganche y fidelización', 'Ruleta, referidos, reseñas con foto, flash sales y wishlist', 'Medio', 'Gratis'],
  ],
));
children.push(SPACER(60));
children.push(P([{ t: '* ', b: true }, { t: 'El seguimiento del delivery es gratuito en su versión con el celular del repartidor como emisor de GPS; el TrackingTag por hardware dedicado implica un costo bajo y opcional.', i: true, c: GREY }]));
children.push(new Paragraph({ spacing: { after: 0 }, children: [new PageBreak()] }));

// ---- 3. n8n transversal ----
children.push(H1('Infraestructura transversal: n8n'));
children.push(P('n8n es una herramienta de automatización y orquestación de flujos de código abierto, que se autoaloja en el servidor propio del equipo (vía Docker) sin costo de licencia. Funciona conectando "nodos" (disparadores y acciones) para automatizar procesos sin escribir un backend adicional para cada integración.'));
children.push(P('En este proyecto, n8n actúa como el "pegamento" de varias propuestas:'));
children.push(BUL('Disparadores programados (cron): por ejemplo, el resumen diario del negocio a una hora fija.'));
children.push(BUL('Webhooks: recibir avisos de Mercado Pago o de un TrackingTag y volcarlos al sistema.'));
children.push(BUL('Mensajería: enviar mensajes de WhatsApp (resúmenes, links de pago, recibos, respuestas del chatbot).'));
children.push(BUL('Inteligencia artificial: llamar a modelos gratuitos (Groq, Gemini o un modelo local con Ollama) para redactar textos o clasificar imágenes.'));
children.push(BUL('Conexión con Britech: consultar la base de datos o los endpoints de la API ya existentes.'));
children.push(P('Al correr sobre el servidor propio, n8n no agrega costos y centraliza las automatizaciones en un panel visual, fácil de mantener y de mostrar en la defensa.'));
children.push(new Paragraph({ spacing: { after: 0 }, children: [new PageBreak()] }));

// ---- Features ----
children.push(...feature('1', 'Asistente de voz manos libres para el vendedor', {
  descripcion: [
    'Un asistente que permite al vendedor operar el POS hablando, sin usar el teclado ni el mouse. Con el micrófono activo, el sistema transcribe lo que dice y lo convierte en acciones reales: buscar y agregar productos a la venta, indicar cantidades, elegir el medio de pago, confirmar o cancelar la venta, e incluso iniciar el alta rápida de un producto.',
    'El micrófono se enciende y se apaga en cualquier momento desde el panel (del administrador o del vendedor) con un botón dedicado, de modo que la escucha solo ocurre cuando el usuario lo decide. Mientras está activo, el sistema va detectando la voz y ejecutando los comandos en vivo.',
  ],
  viabilidad: [
    'Viabilidad alta. El reconocimiento de voz se resuelve con la Web Speech API (SpeechRecognition), incluida de forma nativa y gratuita en los navegadores Chrome y Edge. Para un funcionamiento sin depender de internet o con mayor privacidad, existe la alternativa de Vosk, un motor de reconocimiento de voz de código abierto que corre localmente.',
    'Los comandos se interpretan y se mapean a las acciones del POS reutilizando los endpoints que el sistema ya expone (búsqueda de productos, alta de venta, medios de pago). Opcionalmente, un modelo de lenguaje gratuito (Groq, Gemini u Ollama) puede dar flexibilidad al lenguaje natural, para entender frases dichas de distintas maneras.',
  ],
  recursos: [
    'Navegador Chrome o Edge y un micrófono (los que ya tiene la PC/notebook del local).',
    'Web Speech API (gratuita, nativa del navegador) o Vosk (gratuito, local) para transcribir.',
    'Los endpoints actuales del POS (búsqueda de producto, carrito, cobro).',
    'Opcional: un modelo de lenguaje gratuito para interpretar comandos flexibles.',
    'Opcional: síntesis de voz (Text-to-Speech, también nativa del navegador) para respuestas habladas.',
  ],
  incluye: [
    'Botón de encendido/apagado del micrófono por usuario, visible en el POS y en el panel.',
    'Indicador visual claro de "escuchando" y transcripción en vivo de lo que el sistema entiende.',
    'Comandos: agregar producto, fijar cantidad, elegir medio de pago, confirmar/cancelar venta y alta rápida de producto.',
    'Confirmación (hablada o táctil) antes de acciones sensibles como cobrar o anular.',
    'Apagado automático del micrófono tras un período de inactividad.',
  ],
  fuera: [
    'Dictado libre de textos largos (descripciones extensas) en la primera fase.',
    'Idiomas distintos del español.',
  ],
  verse: [
    'En el encabezado del POS (y del panel) aparece un botón de micrófono con dos estados: apagado (gris) y encendido (con una animación de onda mientras escucha).',
    'Al hablar, un panel flotante muestra en tiempo real la interpretación, por ejemplo: "Entendí: agregar Auricular JBL × 3". En acciones críticas pide una confirmación explícita antes de ejecutar ("¿Confirmás cobrar $18.000 en efectivo?").',
  ],
  plan: [
    'Fase 1: integrar la Web Speech API, agregar el botón de micrófono con estado y mostrar la transcripción en pantalla.',
    'Fase 2: definir la gramática de comandos básicos (agregar / cantidad / cobrar / cancelar) y mapearlos a los endpoints del POS.',
    'Fase 3: sumar confirmaciones de seguridad y respuestas habladas (TTS) para operación 100% manos libres.',
    'Fase 4 (opcional): interpretación flexible con un modelo de lenguaje y alta de producto por voz.',
    'Fase 5: pruebas en condiciones reales de ruido del local y ajuste de sensibilidad.',
  ],
}));

children.push(...feature('2', 'Pago con QR desde el POS (Mercado Pago presencial)', {
  descripcion: 'Durante una venta en el local, el POS genera un código QR de Mercado Pago por el monto exacto de la operación. El cliente lo escanea con su aplicación de Mercado Pago y paga desde su cuenta; el sistema detecta la acreditación automáticamente, registra el pago y cierra la venta. Elimina la necesidad de efectivo o de una terminal POSnet para los pagos digitales presenciales.',
  viabilidad: 'Viabilidad alta. Reutiliza la integración con Mercado Pago que el proyecto ya tiene para el pago online. Mercado Pago ofrece el cobro presencial por QR de forma gratuita con la misma cuenta y credenciales. La confirmación del pago se recibe por webhook, mecanismo ya implementado en Britech.',
  recursos: [
    'Cuenta de Mercado Pago y Access Token (ya disponibles en el proyecto).',
    'API de Mercado Pago (generación de orden/QR de pago presencial).',
    'Librería de generación de códigos QR (gratuita).',
    'Webhook de confirmación (ya existe en el sistema; puede orquestarse también con n8n).',
  ],
  incluye: [
    'Opción "Cobrar con QR" en la pantalla de cobro del POS, junto a Efectivo y Transferencia.',
    'Generación del QR por el monto de la venta y pantalla de espera de pago.',
    'Confirmación automática por webhook y cierre de la venta.',
    'Registro del pago como "Mercado Pago" y asociación a la caja del vendedor.',
  ],
  fuera: [
    'Devoluciones/reembolsos automáticos desde el POS (fase posterior).',
  ],
  verse: 'En la pantalla de cobro del POS, al elegir "Cobrar con QR" se muestra el código en grande con el mensaje "Esperando pago…". Cuando Mercado Pago confirma, la pantalla cambia a "✓ Pago acreditado" y la venta se completa, imprimiéndose o enviándose el ticket.',
  plan: [
    'Fase 1: generar la orden/preferencia de pago presencial y su QR desde el backend.',
    'Fase 2: mostrar el QR en el POS con el estado de la operación en vivo.',
    'Fase 3: confirmar por webhook y cerrar la venta automáticamente.',
    'Fase 4: registrar el pago en la caja y emitir el ticket.',
  ],
}));

children.push(...feature('3', 'Seguimiento del delivery en tiempo real (Moto Formosa + TrackingTag)', {
  descripcion: [
    'Un mapa en vivo que muestra la ubicación de la moto que está realizando un envío en Formosa. El cliente puede seguir su pedido en el mapa con un tiempo estimado de llegada, y el administrador puede ver todas las motos en reparto simultáneamente.',
    'El "TrackingTag" es el emisor de la ubicación. Se propone en dos variantes: una gratuita, usando el propio celular del repartidor como emisor de GPS; y una avanzada, con un dispositivo de rastreo dedicado.',
  ],
  viabilidad: [
    'Viabilidad alta en su versión gratuita (MVP): el repartidor abre en su celular una página web del sistema (instalable como PWA) que envía su posición GPS cada pocos segundos mientras tiene un envío activo. Los mapas se resuelven con Leaflet sobre OpenStreetMap, sin costo de licencia, y la posición se transmite en tiempo real mediante Server-Sent Events o a través de n8n.',
    'La variante avanzada incorpora un TrackingTag por hardware (un rastreador GPS con SIM, o un módulo ESP32 con GPS) que publica su ubicación hacia n8n; esto independiza el seguimiento del celular del repartidor, a un costo bajo y opcional.',
  ],
  recursos: [
    'Leaflet + OpenStreetMap para el mapa (gratuitos).',
    'Geolocation API del navegador para emitir la posición (gratuita).',
    'Canal en tiempo real: Server-Sent Events (nativo en PHP) o n8n/MQTT.',
    'Emisor de GPS: el celular del repartidor (gratis) o, opcionalmente, un TrackingTag por hardware.',
    'Tabla de posiciones en la base de datos para historial y estado actual.',
  ],
  incluye: [
    'Ubicación de la moto en vivo sobre el mapa de Formosa.',
    'Tiempo estimado de llegada (ETA) y trazo de la ruta hacia el destino.',
    'Vista para el cliente ("Seguir mi pedido") y vista global para el administrador ("Envíos en vivo").',
    'Enlace de seguimiento para compartir con el cliente.',
  ],
  fuera: [
    'Optimización automática de rutas con múltiples paradas (se puede sumar luego con OSRM, gratuito).',
  ],
  verse: [
    'Cliente: en "Mis pedidos", un botón "Seguir en el mapa" abre un mapa donde la moto se mueve en tiempo real, con el ETA ("llega en ~12 min").',
    'Administrador: una vista "Envíos en vivo" con el mapa de Formosa y cada moto como un ícono que se desplaza, pudiendo hacer clic para ver qué pedido lleva.',
  ],
  plan: [
    'Fase 1: página del repartidor (PWA) que emite la posición GPS y la guarda en la base de datos.',
    'Fase 2: canal en tiempo real (SSE) y mapa con Leaflet en las vistas del cliente y del administrador.',
    'Fase 3: cálculo del ETA y trazado de la ruta hacia el destino.',
    'Fase 4 (opcional): integración del TrackingTag por hardware a través de n8n/MQTT.',
  ],
}));

children.push(...feature('4', 'Integraciones con WhatsApp (vía n8n)', {
  descripcion: [
    'Un conjunto de automatizaciones que usan WhatsApp como canal de comunicación con el dueño del negocio y con los clientes, orquestadas desde n8n. Incluye cuatro funciones:',
    '(a) Resumen diario del negocio: cada noche, un mensaje automático al dueño con las ventas del día, el producto más vendido, alertas de stock y el estado de la caja. (b) Link de pago de Mercado Pago: al generarse un pedido, se envía al cliente el enlace para pagar. (c) Recibo/ticket por WhatsApp: al cerrar una venta o pedido, se envía el comprobante en imagen o PDF, en lugar de papel. (d) Pedido por chatbot: un flujo conversacional por el cual el cliente puede armar su pedido chateando.',
  ],
  viabilidad: [
    'Viabilidad alta para las funciones (a), (b) y (c); la (d), el chatbot de pedidos, es de mayor complejidad y se plantea como fase avanzada. n8n, corriendo en el servidor propio, orquesta todas las automatizaciones.',
    'La conexión con WhatsApp puede realizarse mediante la WhatsApp Cloud API oficial de Meta (con su nivel gratuito) o mediante Evolution API, una solución de código abierto autoalojable y gratuita que vincula un número de WhatsApp. Los textos del resumen diario y las respuestas del chatbot pueden redactarse con un modelo de lenguaje gratuito.',
  ],
  recursos: [
    'n8n en el servidor propio (gratuito).',
    'Un número de WhatsApp y la WhatsApp Cloud API (nivel gratuito) o Evolution API (gratuita, autoalojada).',
    'Acceso a la API/base de datos de Britech para obtener las métricas y los datos del pedido.',
    'Un modelo de lenguaje gratuito (Groq, Gemini u Ollama) para redactar el resumen y, eventualmente, el chatbot.',
  ],
  incluye: [
    'Resumen diario automático al dueño a una hora configurable.',
    'Envío del link de pago de Mercado Pago al cliente.',
    'Envío del recibo/ticket por WhatsApp.',
    'Interruptores en el panel para activar o desactivar cada automatización.',
  ],
  fuera: [
    'Chatbot conversacional completo para armar pedidos (fase avanzada).',
    'Campañas masivas de marketing por WhatsApp.',
  ],
  verse: [
    'El dueño recibe en su WhatsApp un mensaje como: "Resumen de hoy — Ventas: $142.000 (7 ventas). Top: Auricular JBL. ⚠ Stock bajo: iPhone 15 Pro (2u). Caja: cuadrada ✓".',
    'El cliente recibe el link de pago y, al finalizar, el ticket. En el panel, una sección "Automatizaciones" permite prender/apagar cada flujo.',
  ],
  plan: [
    'Fase 1: conectar n8n con WhatsApp (Evolution API o Cloud API) y validar el envío de un mensaje de prueba.',
    'Fase 2: automatización del resumen diario (cron → consulta de métricas → redacción → envío).',
    'Fase 3: envío del link de pago y del recibo/ticket al cliente.',
    'Fase 4 (avanzada): chatbot conversacional para tomar pedidos.',
  ],
}));

children.push(...feature('5', 'Auto-etiquetado de productos por foto (IA)', {
  descripcion: 'Al dar de alta un producto, el administrador sube la foto y el sistema sugiere automáticamente la categoría y la marca (e incluso una descripción), a partir del análisis de la imagen con inteligencia artificial. El administrador confirma o corrige la sugerencia, acelerando la carga del catálogo y mejorando su consistencia.',
  viabilidad: 'Viabilidad alta. El análisis de imagen se puede resolver de forma gratuita con un modelo de visión a través de un servicio con nivel gratuito (Gemini) o con un modelo local mediante Ollama (por ejemplo, LLaVA); también existe la opción de clasificación directamente en el navegador con TensorFlow.js (MobileNet). n8n puede orquestar la llamada al modelo si se desea.',
  recursos: [
    'Un modelo de visión gratuito: Gemini (nivel gratuito), Ollama local (LLaVA) o TensorFlow.js en el navegador.',
    'Un endpoint que reciba la imagen y devuelva la sugerencia.',
    'El catálogo de categorías y marcas existente, para acotar las sugerencias.',
    'Opcional: n8n para orquestar la llamada al modelo.',
  ],
  incluye: [
    'Sugerencia de categoría y marca a partir de la imagen del producto.',
    'Confirmación humana obligatoria antes de guardar.',
    'Opcional: sugerencia de nombre y descripción.',
  ],
  fuera: [
    'Detección de stock o conteo de unidades por foto.',
    'Reconocimiento de productos en góndola.',
  ],
  verse: 'En el formulario de alta de producto, al soltar o elegir la imagen aparece un cartel: "Sugerencia de IA: Categoría = Auriculares · Marca = JBL", con botones "Usar sugerencia" y "Descartar". El administrador decide siempre.',
  plan: [
    'Fase 1: endpoint que recibe la imagen y la envía al modelo de visión, devolviendo la categoría y la marca.',
    'Fase 2: integración de la sugerencia en el formulario de alta, con confirmación humana.',
    'Fase 3: ajuste de las instrucciones (prompt) al catálogo real y, opcionalmente, sugerencia de descripción.',
  ],
}));

children.push(...feature('6', 'Módulo de enganche y fidelización', {
  descripcion: [
    'Un conjunto de funciones orientadas a aumentar la recompra y la participación de los clientes de la tienda. Reúne cinco mecánicas:',
    'Ruleta/raspadita de descuento tras una compra; programa de referidos (invitar a un amigo otorga beneficios a ambos); reseñas con foto de clientes verificados (solo quien compró el producto puede reseñarlo); ofertas relámpago (flash sales) con cuenta regresiva; y lista de deseos (wishlist) que se puede compartir o usar para "regalar" un producto a otra persona.',
  ],
  viabilidad: 'Viabilidad alta. Todas estas funciones se implementan con el stack actual del proyecto (PHP, MySQL y el frontend existente), sin servicios externos ni costos. Requieren principalmente tablas nuevas y pantallas o componentes en la tienda.',
  recursos: [
    'Tablas nuevas en MySQL (puntos/cupones, referidos, reseñas, lista de deseos).',
    'Componentes de interfaz en la tienda (modales, animaciones, contadores).',
    'La lógica de pedidos existente, para validar que una reseña provenga de una compra real.',
    'Nada de servicios de pago: todo es desarrollo propio y gratuito.',
  ],
  incluye: [
    'Ruleta/raspadita que entrega un cupón de descuento al finalizar la compra.',
    'Programa de referidos con enlace propio por cliente y beneficio al concretarse una compra.',
    'Reseñas con foto, habilitadas únicamente para clientes que compraron el producto (reseña verificada).',
    'Ofertas relámpago con cuenta regresiva visible en la tienda.',
    'Lista de deseos compartible y opción de "regalar" un producto.',
  ],
  fuera: [
    'Niveles/categorías de clientes (VIP) y campañas automatizadas (posible extensión futura).',
  ],
  verse: [
    'Al terminar una compra, aparece una ruleta animada que premia con un cupón. En la ficha del producto se ven reseñas con foto marcadas como "Compra verificada". Las ofertas muestran un reloj de cuenta regresiva. Desde la cuenta, el cliente arma su lista de deseos y la comparte por un enlace.',
  ],
  plan: [
    'Fase 1: modelo de datos (cupones, referidos, reseñas, wishlist) y lógica base.',
    'Fase 2: reseñas con foto verificadas y lista de deseos compartible.',
    'Fase 3: ruleta/raspadita de cupones y ofertas relámpago con cuenta regresiva.',
    'Fase 4: programa de referidos de punta a punta (enlace, atribución y beneficio).',
  ],
}));

// ---- Cronograma ----
children.push(H1('Cronograma general sugerido'));
children.push(P('Se propone una implementación por fases, priorizando las propuestas de mayor impacto y menor dependencia. Cada fase es verificable de forma independiente, dejando el sistema siempre funcional.'));
children.push(table(
  [{ h: 'Etapa', w: 1200, align: AlignmentType.CENTER }, { h: 'Foco', w: 3900 }, { h: 'Propuestas', w: 2720 }, { h: 'Duración est.', w: 1200, align: AlignmentType.CENTER }],
  [
    ['Etapa 1', 'Cobro digital presencial y base de automatizaciones', '2 · 4 (a/b/c)', '2–3 sem.'],
    ['Etapa 2', 'Experiencia del cliente y carga asistida', '5 · 6', '2–3 sem.'],
    ['Etapa 3', 'Logística en tiempo real', '3', '2 sem.'],
    ['Etapa 4', 'Operación manos libres (voz)', '1', '2–3 sem.'],
    ['Etapa 5', 'Funciones avanzadas', '1 (IA) · 4 (chatbot) · 3 (TrackingTag HW)', 'A definir'],
  ],
));
children.push(SPACER(80));

// ---- Consideraciones ----
children.push(H1('Consideraciones de costos, seguridad y privacidad'));
children.push(H3('Costos'));
children.push(P('Todas las propuestas son de implementación gratuita en su versión base: utilizan APIs nativas del navegador, software de código abierto (n8n, Evolution API, Vosk, Ollama, Leaflet) o niveles gratuitos de servicios (Mercado Pago, Gemini, Groq, WhatsApp Cloud API). El único costo opcional es el TrackingTag por hardware en la propuesta 3, que no es imprescindible.'));
children.push(H3('Seguridad'));
children.push(BUL('El asistente de voz pide confirmación explícita antes de cobrar o anular, y la escucha solo ocurre con el micrófono encendido manualmente.'));
children.push(BUL('Los cobros con Mercado Pago (QR) se confirman por verificación con el servidor de Mercado Pago, sin confiar en datos del navegador, igual que el flujo online ya existente.'));
children.push(BUL('Las reseñas se validan contra compras reales (reseña verificada), evitando reseñas falsas.'));
children.push(H3('Privacidad'));
children.push(BUL('La ubicación del repartidor se emite solo durante un envío activo y se usa exclusivamente para el seguimiento.'));
children.push(BUL('Las credenciales y tokens (Mercado Pago, WhatsApp, modelos de IA) se guardan como variables de entorno, nunca en el repositorio ni expuestas al cliente.'));
children.push(BUL('Las opciones locales (Vosk, Ollama) permiten procesar voz e imágenes sin enviar datos a terceros.'));
children.push(SPACER(80));

// ---- Conclusión ----
children.push(H1('Conclusión'));
children.push(P('Las seis propuestas combinan innovación, utilidad operativa real y costo nulo de implementación, apoyándose en la arquitectura ya consolidada de Britech y en la disponibilidad de un servidor propio para automatizaciones con n8n. Su incorporación posicionaría a Britech por encima de un trabajo práctico estándar, demostrando integración con hardware (voz, GPS, QR), con servicios de pago, con mensajería (WhatsApp) y con inteligencia artificial, siempre de forma funcional y verificable.'));
children.push(P('Se solicita la evaluación de estas propuestas para definir, en conjunto con la cátedra, el alcance y la priorización de su implementación.'));

// ========================================================= DOC
const doc = new Document({
  creator: 'Equipo Britech',
  title: 'Propuesta de Implementaciones Tecnológicas — Britech',
  description: 'Documento formal de implementación para la cátedra',
  numbering: {
    config: [
      { reference: 'bul', levels: [{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: 460, hanging: 260 } } } }] },
      { reference: 'steps', levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: 460, hanging: 260 } } } }] },
    ],
  },
  styles: {
    default: { document: { run: { font: 'Calibri', size: 22, color: '222222' } } },
  },
  sections: [{
    properties: { page: { margin: { top: 1440, bottom: 1440, left: 1440, right: 1440 } } },
    headers: { default: new Header({ children: [new Paragraph({
      alignment: AlignmentType.RIGHT, spacing: { after: 0 },
      border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: LINE, space: 6 } },
      children: [new TextRun({ text: 'Britech · Propuesta de Implementaciones Tecnológicas', color: GREY, size: 16 })] })] }) },
    footers: { default: new Footer({ children: [new Paragraph({
      alignment: AlignmentType.CENTER, spacing: { before: 0 },
      children: [new TextRun({ text: 'Página ', color: GREY, size: 16 }),
        new TextRun({ children: [PageNumber.CURRENT], color: GREY, size: 16 }),
        new TextRun({ text: ' de ', color: GREY, size: 16 }),
        new TextRun({ children: [PageNumber.TOTAL_PAGES], color: GREY, size: 16 })] })] }) },
    children,
  }],
});

Packer.toBuffer(doc).then((buf) => {
  fs.writeFileSync('Britech-Propuestas-Tecnologicas.docx', buf);
  console.log('OK ' + buf.length + ' bytes');
});
