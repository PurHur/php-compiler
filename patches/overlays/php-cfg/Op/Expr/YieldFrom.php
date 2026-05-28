<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PhpCfg\Operand;

/**
 * PHP 7+ `yield from` expression (issue #167).
 */
class YieldFrom extends Expr
{
    public $expr;

    protected $writeVariables = ['result'];

    public function __construct(Operand $expr, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->expr = $this->addReadRef($expr);
    }

    public function getVariableNames(): array
    {
        return ['expr', 'result'];
    }
}

