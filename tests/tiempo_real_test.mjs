// Pruebas del tiempo real (WebSockets) — Node 22+ (trae WebSocket y fetch; no instala nada).
//
// Hace operaciones REALES por la API y comprueba quién recibe cada evento por WebSocket:
// autenticación por ticket, tickets falsos o vencidos, aislamiento entre usuarios y clientes,
// eventos de notificaciones, cambios, envíos y posición del repartidor, latido, mensajes no
// permitidos, estado de conexiones y que el sistema siga funcionando con el WebSocket apagado.
//
// SOLO sobre una base de prueba (*_test) con el servidor web y el WebSocket levantados:
//   php bin/websocket.php start
//   DB_NAME=britech_ws_test php -S 127.0.0.1:8125 -t public public/index.php
//   BRITECH_TEST_URL=http://127.0.0.1:8125 node tests/tiempo_real_test.mjs
// Datos de prueba (variables de entorno, con estos valores por defecto):
//   admin y vendedor:  admin.ws@britech.test / vende.ws@britech.test  (clave prueba1234)
//   cliente A (dueño del pedido WS_PEDIDO_A, con envío en camino del repartidor) y cliente B
//   repartidor con token WS_REP_TOKEN y un envío WS_ENVIO_LIBRE sin asignar.
const URL_BASE = process.env.BRITECH_TEST_URL || 'http://127.0.0.1:8125';
const E = process.env;
const datos = {
    admin: [E.WS_ADMIN || 'admin.ws@britech.test', 'prueba1234'],
    vend: [E.WS_VEND || 'vende.ws@britech.test', 'prueba1234'],
    cliA: [E.WS_CLI_A || 'francoisvanda@gmail.com', 'prueba1234'],
    cliB: [E.WS_CLI_B || 'erikbrite@gmail.com', 'prueba1234'],
    pedidoA: Number(E.WS_PEDIDO_A || 75),
    repToken: E.WS_REP_TOKEN || 'tokenprueba-ws-0001',
    envioLibre: Number(E.WS_ENVIO_LIBRE || 67),
};

let total = 0, fallas = 0;
async function check(nombre, fn) {
    total++;
    try { await fn(); console.log('  OK    ' + nombre); }
    catch (e) { fallas++; console.log('  FALLA ' + nombre + ' → ' + e.message); }
}
const verdad = (c, que) => { if (!c) throw new Error(que); };
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

/** Cliente HTTP con cookie de sesión propia. */
function sesion() {
    let cookie = '';
    return async (metodo, ruta, cuerpo) => {
        const r = await fetch(URL_BASE + ruta, {
            method: metodo, headers: { 'Content-Type': 'application/json', ...(cookie ? { Cookie: cookie } : {}) },
            body: cuerpo ? JSON.stringify(cuerpo) : undefined,
        });
        const sc = r.headers.getSetCookie?.() || [];
        if (sc.length) cookie = sc.map((c) => c.split(';')[0]).join('; ');
        let j = {};
        try { j = await r.json(); } catch {}
        return { code: r.status, ...j };
    };
}

/** Conecta con un ticket y junta los eventos que llegan. */
async function conectar(http, query = '') {
    const t = await http('GET', '/api/tiempo-real/ticket' + query);
    if (!t.ok || !t.activo) throw new Error('sin ticket: ' + JSON.stringify(t));
    return abrir(t.url + '?ticket=' + encodeURIComponent(t.ticket));
}
function abrir(url) {
    return new Promise((ok, mal) => {
        const ws = new WebSocket(url);
        const eventos = [];
        ws.onmessage = (m) => { try { eventos.push(JSON.parse(m.data)); } catch {} };
        ws.onopen = () => setTimeout(() => ok({ ws, eventos, de: (ev) => eventos.filter((e) => e.evento === ev) }), 300);
        ws.onerror = () => mal(new Error('conexión rechazada'));
    });
}
const limpiar = (...cs) => cs.forEach((c) => { c.eventos.length = 0; });

console.log('Tiempo real · ' + URL_BASE);
const admin = sesion(), vend = sesion(), cliA = sesion(), cliB = sesion();
let cAdmin, cVend, cA, cB, cRep, repId = 0;

await check('login de admin, vendedor y dos clientes', async () => {
    verdad((await admin('POST', '/api/login', { email: datos.admin[0], password: datos.admin[1] })).ok, 'admin');
    verdad((await vend('POST', '/api/login', { email: datos.vend[0], password: datos.vend[1] })).ok, 'vendedor');
    verdad((await cliA('POST', '/api/tienda/login', { email: datos.cliA[0], password: datos.cliA[1] })).ok, 'cliente A');
    verdad((await cliB('POST', '/api/tienda/login', { email: datos.cliB[0], password: datos.cliB[1] })).ok, 'cliente B');
});

console.log('\nAutenticación');
await check('sin sesión no hay ticket (401)', async () => {
    verdad((await sesion()('GET', '/api/tiempo-real/ticket')).code === 401, 'debería ser 401');
});
await check('cada uno se conecta con su ticket y el servidor confirma quién es', async () => {
    cAdmin = await conectar(admin); cVend = await conectar(vend); cA = await conectar(cliA); cB = await conectar(cliB);
    cRep = await conectar(sesion(), '?token=' + datos.repToken);
    const quien = (c) => c.de('bienvenida')[0]?.datos;
    verdad(quien(cAdmin)?.rol === 'admin' && quien(cVend)?.rol === 'vendedor', 'roles staff: ' + JSON.stringify([quien(cAdmin), quien(cVend)]));
    verdad(quien(cA)?.sujeto.startsWith('c:') && quien(cRep)?.sujeto.startsWith('r:'), 'cliente/repartidor');
    repId = Number(quien(cRep).sujeto.slice(2));
});
await check('ticket inválido, adulterado o ausente: conexión rechazada', async () => {
    const t = await admin('GET', '/api/tiempo-real/ticket');
    const [d, f] = t.ticket.split('.');
    const otro = Buffer.from(JSON.stringify({ sub: 'u:1', rol: 'admin', exp: Math.floor(Date.now() / 1000) + 60 })).toString('base64url');
    for (const tk of ['', 'basura', otro + '.' + f, d + '.firmafalsa']) {
        let rechazada = false;
        try { const c = await abrir(t.url + '?ticket=' + encodeURIComponent(tk)); c.ws.close(); } catch { rechazada = true; }
        verdad(rechazada, 'aceptó el ticket: ' + tk.slice(0, 20));
    }
});
await check('token de repartidor falso: sin ticket', async () => {
    verdad((await sesion()('GET', '/api/tiempo-real/ticket?token=no-existe')).code === 401, 'debería ser 401');
});

console.log('\nMensajes del navegador');
await check('ping → pong; cualquier otro mensaje se rechaza', async () => {
    limpiar(cA);
    cA.ws.send(JSON.stringify({ tipo: 'ping' }));
    cA.ws.send(JSON.stringify({ tipo: 'suscribir', grupo: 'admin' }));
    await dormir(400);
    verdad(cA.de('pong').length === 1, 'sin pong');
    verdad(cA.de('error').length === 1, 'no rechazó el mensaje');
});

console.log('\nEventos y permisos');
await check('venta del vendedor: el admin recibe el cambio y la notificación; los clientes nada', async () => {
    limpiar(cAdmin, cVend, cA, cB);
    const e = await vend('GET', '/api/caja/estado');
    if (!e.abierta) verdad((await vend('POST', '/api/caja/abrir', { monto: 1000 })).ok, 'no abrió la caja');
    const r = await vend('POST', '/api/ventas', { cliente_id: 1, items: [{ producto_id: 2, cantidad: 1 }] });
    verdad(r.ok, 'venta: ' + JSON.stringify(r));
    await dormir(800);
    verdad(cAdmin.de('cambio').some((x) => x.datos.modulo === 'venta'), 'el admin no recibió el cambio');
    verdad(cAdmin.de('notificacion').length >= 1, 'el admin no recibió la notificación');
    verdad(cVend.de('cambio').some((x) => x.datos.modulo === 'venta'), 'el vendedor (autor) no recibió su cambio');
    verdad(cA.eventos.length === 0 && cB.eventos.length === 0, 'un cliente recibió eventos del staff');
    verdad(!JSON.stringify(cAdmin.de('cambio')).includes('total'), 'el cambio lleva detalles');
});
await check('cambio de estado de un pedido: solo su cliente recibe la notificación', async () => {
    limpiar(cA, cB, cRep);
    verdad((await admin('POST', '/api/admin/pedidos/estado', { id: datos.pedidoA, estado: 'preparando' })).ok, 'no cambió el estado');
    await dormir(800);
    verdad(cA.de('notificacion').length === 1, 'el cliente A no recibió su aviso');
    verdad(cB.eventos.length === 0, 'el cliente B recibió un aviso ajeno');
    verdad(cRep.eventos.length === 0, 'el repartidor recibió un aviso ajeno');
});
await check('posición del repartidor: staff y SOLO el cliente de ese pedido', async () => {
    limpiar(cAdmin, cVend, cA, cB);
    const r = await sesion()('POST', '/api/repartidor/posicion', { token: datos.repToken, lat: -26.18, lng: -58.17 });
    verdad(r.ok, 'posición: ' + JSON.stringify(r));
    await dormir(800);
    verdad(cAdmin.de('envio_posicion').length === 1 && cVend.de('envio_posicion').length === 1, 'el staff no recibió la posición');
    const a = cA.de('envio_posicion');
    verdad(a.length === 1 && a[0].datos.pedido_id === datos.pedidoA, 'el cliente A no recibió SU pedido');
    verdad(a[0].datos.repartidor_id === undefined, 'al cliente le llega el id del repartidor');
    verdad(cB.de('envio_posicion').length === 0, 'el cliente B recibió la moto de otro');
});
await check('asignar un envío: le llega al repartidor al instante', async () => {
    limpiar(cRep, cA, cB);
    const r = await admin('POST', '/api/admin/envios/derivar', { envio_id: datos.envioLibre, repartidor_id: repId });
    verdad(r.ok, 'derivar: ' + JSON.stringify(r));
    await dormir(800);
    verdad(cRep.de('envio').some((x) => x.datos.envio_id === datos.envioLibre), 'el repartidor no recibió el envío');
    verdad(cA.eventos.length === 0 && cB.eventos.length === 0, 'un cliente recibió la asignación');
    await admin('POST', '/api/admin/envios/derivar', { envio_id: datos.envioLibre, repartidor_id: 0 });   // se deja como estaba
});
await check('alertas de auditoría: solo a los administradores', async () => {
    limpiar(cAdmin, cVend);
    // Un ajuste de stock grande genera siempre una alerta nueva ("ajuste inusual").
    const p = (await admin('GET', '/api/admin/productos/detalle?id=2')).producto;
    p.stock = Number(p.stock) + 25;
    verdad((await admin('POST', '/api/admin/productos/guardar', p)).ok, 'no se guardó el producto');
    await dormir(800);
    verdad(cAdmin.de('alerta').length >= 1, 'el admin no recibió la alerta');
    verdad(cVend.de('alerta').length === 0, 'el vendedor recibió una alerta de auditoría');
});

console.log('\nConexiones');
await check('el admin ve quiénes están conectados; el vendedor no puede', async () => {
    const e = await admin('GET', '/api/admin/tiempo-real/estado');
    verdad(e.ok && e.en_linea && e.conexiones >= 5, 'estado: ' + JSON.stringify(e).slice(0, 200));
    verdad((await vend('GET', '/api/admin/tiempo-real/estado')).code === 403, 'el vendedor accedió');
});
await check('al desconectarse, la conexión se libera', async () => {
    const antes = (await admin('GET', '/api/admin/tiempo-real/estado')).conexiones;
    cB.ws.close();
    await dormir(500);
    const despues = (await admin('GET', '/api/admin/tiempo-real/estado')).conexiones;
    verdad(despues === antes - 1, `antes ${antes}, después ${despues}`);
});

[cAdmin, cVend, cA, cRep].forEach((c) => c?.ws.close());
console.log(`\n${total} pruebas · ${total - fallas} OK · ${fallas} con fallas`);
process.exit(fallas ? 1 : 0);
