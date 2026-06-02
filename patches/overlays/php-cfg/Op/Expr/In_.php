<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PhpCfg\Operand;

/** PHP 8.3+ `$needle in $haystack` contains expression (#4682). */
class In_ extends Expr
{
    public $expr;

    public $haystack;

    public function __construct(Operand $expr, Operand $haystack, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->expr = $this->addReadRef($expr);
        $this->haystack = $this->addReadRef($haystack);
    }

    public function getVariableNames(): array
    {
        return ['expr', 'haystack', 'result'];
    }
}
