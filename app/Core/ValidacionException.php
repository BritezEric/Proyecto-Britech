<?php

namespace App\Core;

use RuntimeException;

/**
 * Error de validación / regla de negocio (ej: stock insuficiente, pago que no
 * cuadra). Se usa para distinguir estos errores "esperables" (que SÍ le
 * mostramos al usuario) de los errores internos de base (que NO exponemos).
 */
class ValidacionException extends RuntimeException
{
}
