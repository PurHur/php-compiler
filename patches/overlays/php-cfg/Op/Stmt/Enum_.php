<?php

declare(strict_types=1);

namespace PHPCfg\Op\Stmt;

use PHPCfg\Block;
use PHPCfg\Op\Type;
use PHPCfg\Operand;

class Enum_ extends ClassLike
{
    /** @var Type|null Backing scalar type (string|int) when declared as `enum Foo: string` */
    public $backedType = null;

    /** @var Operand[] Implemented interface name operands */
    public $implements = [];

    public function __construct(Operand $name, ?Type $backedType, array $implements, Block $stmts, array $attributes = [])
    {
        parent::__construct($name, $stmts, $attributes);
        $this->backedType = $backedType;
        $this->implements = $implements;
    }

    public function getVariableNames(): array
    {
        return ['name', 'implements'];
    }
}
