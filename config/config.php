<?php
/**
 * Configuracion central de la aplicacion.
 * Lee las credenciales del entorno:
 *  - En Laragon: desde el archivo .env
 *  - En Docker:  desde las variables que define docker-compose.yml
 * Devuelve un array con toda la config para que el resto del sistema la use.
 */

// 1. Carga el autoload de Composer (una sola vez).
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 2. Carga el .env siempre. createImmutable NO pisa las variables que el entorno
//    ya definió (las de docker-compose: DB_*, VOZ_API_KEY), así que esto solo
//    SUMA las que falten (GROQ_API_KEY, ELEVENLABS_API_KEY…) tanto en Laragon
//    como en Docker. safeLoad no rompe si el archivo .env no existe.
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

// Lee una variable: primero del entorno real (Docker), luego del .env (Laragon).
$leer = fn(string $clave, ?string $def = null): ?string
    => getenv($clave) !== false ? getenv($clave) : ($_ENV[$clave] ?? $def);

// 3. Devuelve la config lista para usar.
return [
    'app' => [
        'nombre'  => $leer('APP_NAME', 'Britech'),
        'entorno' => $leer('APP_ENV', 'local'),
        'debug'   => $leer('APP_DEBUG', 'false') === 'true',
        'url'     => $leer('APP_URL', 'http://127.0.0.1:8123'),
    ],
    'db' => [
        'host'    => $leer('DB_HOST', '127.0.0.1'),
        'port'    => $leer('DB_PORT', '3306'),
        'name'    => $leer('DB_NAME', ''),
        'user'    => $leer('DB_USER', 'root'),
        'pass'    => $leer('DB_PASS', ''),
        'charset' => $leer('DB_CHARSET', 'utf8mb4'),
    ],
    'mail' => [
        'host'        => $leer('MAIL_HOST', 'smtp.gmail.com'),
        'port'        => $leer('MAIL_PORT', '587'),
        'secure'      => $leer('MAIL_SECURE', 'tls'),
        'user'        => $leer('MAIL_USER', ''),
        'password'    => $leer('MAIL_PASSWORD', ''),
        'from_email'  => $leer('MAIL_FROM_EMAIL', 'no-reply@britech.local'),
        'from_nombre' => $leer('MAIL_FROM_NOMBRE', 'Britech'),
    ],
    // Login social con Google vía Auth0 (opcional: si no está configurado, el
    // botón "Continuar con Google" avisa y sigue andando el login por email).
    'auth0' => [
        'domain'        => $leer('AUTH0_DOMAIN', ''),
        'client_id'     => $leer('AUTH0_CLIENT_ID', ''),
        'client_secret' => $leer('AUTH0_CLIENT_SECRET', ''),
        'cookie_secret' => $leer('AUTH0_COOKIE_SECRET', ''),
        'redirect_uri'  => $leer('AUTH0_REDIRECT_URI',
            $leer('APP_URL', 'http://127.0.0.1:8123') . '/api/tienda/auth0/callback'),
        // A dónde vuelve Auth0 tras cerrar su sesión (debe estar en "Allowed Logout URLs").
        'logout_uri'    => $leer('AUTH0_LOGOUT_URI',
            $leer('APP_URL', 'http://127.0.0.1:8123') . '/tienda.html'),
    ],
    // Mercado Pago (Checkout Pro) para el pago online — opcional: si el access
    // token queda vacío, el checkout usa el flujo manual de transferencia + comprobante.
    'mercadopago' => [
        'access_token' => $leer('MERCADOPAGO_ACCESS_TOKEN', ''),
    ],
    // Asistente de voz / n8n: API-key para que un sistema sin navegador llame a
    // la API. Si la clave queda vacía, el acceso por API-key está desactivado.
    'voz' => [
        'api_key'     => $leer('VOZ_API_KEY', ''),
        'usuario_id'  => (int) $leer('VOZ_USUARIO_ID', '1'),  // como quién opera (default: admin)
        // Webhook de n8n al que el POS reenvía el texto. En Docker, n8n es un
        // contenedor de la misma red (http://n8n:5678). En local, apuntar al túnel.
        'n8n_url'     => $leer('VOZ_N8N_URL', 'http://n8n:5678/webhook/voz'),
        // STT: el backend transcribe el audio con Gemini (así la voz anda en
        // cualquier navegador, incluido Brave que bloquea el Web Speech de Google).
        'gemini_key'   => $leer('GEMINI_API_KEY', ''),
        'gemini_model' => $leer('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        // TTS con voz natural (ElevenLabs). Si la key queda vacía, el POS usa
        // el TTS del navegador como respaldo. Voice por defecto: "Sarah" (multilingüe).
        'elevenlabs_key'   => $leer('ELEVENLABS_API_KEY', ''),
        'elevenlabs_voice' => $leer('ELEVENLABS_VOICE_ID', 'cjVigY5qzO86Huf0OWal'), // Eric
        'elevenlabs_model' => $leer('ELEVENLABS_MODEL', 'eleven_flash_v2_5'),        // flash = rápido
        // Groq (LPU): transcripción Whisper + cerebro Llama, latencia casi nula.
        // Si GROQ_API_KEY está seteada, la transcripción usa Groq; si no, Gemini.
        'groq_key'     => $leer('GROQ_API_KEY', ''),
        'groq_stt'     => $leer('GROQ_STT_MODEL', 'whisper-large-v3-turbo'),
        'groq_model'   => $leer('GROQ_MODEL', 'llama-3.3-70b-versatile'),
    ],
    // Mapas / ruteo del seguimiento de envíos (estilo Uber).
    'mapas' => [
        // OpenRouteService: ruta real por las calles + ETA. Key gratis en openrouteservice.org.
        // Si queda vacía, se usa el servidor demo de OSRM (sin key, solo para la demo).
        'ors_key'   => $leer('ORS_API_KEY', ''),
        // Punto de retiro (el local): a dónde va el cadete a buscar el paquete.
        'local_lat' => (float) $leer('LOCAL_LAT', '-26.1861644'),
        'local_lng' => (float) $leer('LOCAL_LNG', '-58.1661652'),
        'local_dir' => $leer('LOCAL_DIRECCION', 'Hipólito Yrigoyen 3563, Formosa'),
        // Acotar la geocodificación a Formosa capital (evita que Nominatim matchee
        // calles del mismo nombre en otra parte). viewbox = lon_izq,lat_sup,lon_der,lat_inf.
        'geo_viewbox' => $leer('GEO_VIEWBOX', '-58.30,-26.10,-58.05,-26.28'),
    ],
];
