<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;

/**
 * Runtime __DIR__ / __FILE__ / __LINE__ (issues #707, #715). Lowered to TYPE_SCRIPT_MAGIC in the compiler.
 */
class MagicScriptConst extends Expr
{
    public const KIND_DIR = 1;

    public const KIND_FILE = 2;

    public const KIND_LINE = 3;

    public int $kind;

    public function __construct(int $kind, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->kind = $kind;
    }

    public function getVariableNames(): array
    {
        return ['result'];
    }
}
