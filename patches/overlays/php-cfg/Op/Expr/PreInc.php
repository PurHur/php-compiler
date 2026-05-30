<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PHPCfg\Operand;

class PreInc extends Expr
{
    public $read;

    public $write;

    public function __construct(Operand $read, Operand $write, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->read = $this->addReadRef($read);
        $this->write = $this->addWriteRef($write);
    }

    public function getVariableNames(): array
    {
        return ['read', 'write', 'result'];
    }
}
