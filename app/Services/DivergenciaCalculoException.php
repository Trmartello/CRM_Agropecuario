<?php

namespace App\Services;

/**
 * Cálculo do cliente ≠ cálculo do servidor ao salvar cenário (spec
 * custo-lavoura §9): vira HTTP 409 no controller e é registrado em log.
 */
class DivergenciaCalculoException extends \RuntimeException
{
}
