<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/** Shared stream argument helpers (issue #3755). */
final class VmStreamArg
{
    public static function debugTypeName(Variable $v): string
    {
        switch ($v->type) {
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
