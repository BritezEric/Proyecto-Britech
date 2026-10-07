# Exposición — reparto por integrante

Guía para que cada uno defienda **su parte del código** en la exposición. Cada
documento explica qué es el módulo, **qué archivos lo componen** (con la ruta para
abrirlos), las rutas de la API, el flujo y las **preguntas típicas** que te pueden
hacer.

| Integrante | Módulo | Documento |
|---|---|---|
| **Tobi** | Notificaciones | [notificaciones-tobi.md](notificaciones-tobi.md) |
| **Franco** | Salidas del Sistema (reportes + cierre de caja + PDF) | [salidas-franco.md](salidas-franco.md) |
| **Eric** | Transacciones (Ventas, Compras, Pedidos, Pago) | [transacciones-eric.md](transacciones-eric.md) |

## Arquitectura común (la sabe todo el equipo)

Britech es **PHP puro con MVC por capas**. Todo pedido entra por un único punto y
baja por las mismas capas:

```
Navegador (fetch a /api/...)
   │
public/index.php        (Front Controller: arranca todo)
   │
routes/api.php          (Router: qué controlador atiende cada URL)
   │
Controllers/            (reciben la petición, validan sesión, responden JSON)
   │
Services/               (lógica de negocio: precios, stock, reglas, transacciones)
   │
Repositories/           (único lugar con SQL, siempre con PDO preparado)
   │
MySQL (britech_v2)
```

Reglas que conviene repetir en la defensa:
- **El precio y el stock se calculan SIEMPRE en el backend**, nunca se confía en
  lo que manda el navegador.
- **SQL con sentencias preparadas** (PDO) en los Repositories → sin inyección SQL.
- **Sesiones separadas**: `staff` (admin/vendedor) y `cliente` son identidades
  distintas y mutuamente excluyentes (`app/Core/Session.php`).
