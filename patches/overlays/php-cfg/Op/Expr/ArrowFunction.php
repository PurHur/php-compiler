<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Func;
use PHPCfg\Op\CallableOp;
use PHPCfg\Op\Expr;

/** PHP 7.4+ arrow function: parse-only CFG node for bootstrap spine (#2574). */
class ArrowFunction extends Expr implements CallableOp
{
    public $func;

    public function __construct(Func $func, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->func = $func;
    }

    public function getVariableNames(): array
    {
        return ['result'];
    }

    public function getFunc(): Func
    {
        return $this->func;
    }

    public function getType(): string
    {
        return 'Expr_ArrowFunction';
    }
}
