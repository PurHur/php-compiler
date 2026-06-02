<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/** VM lowering for exit/die (issue #269). */
final class VmExit
{
    public static function terminate(?Variable $arg): never
    {
        $status = self::resolveStatus($arg);
        $ctx = Superglobals::getActiveContext();
        if (null !== $ctx) {
            ShutdownQueue::run($ctx);
        }
        throw new ScriptExit($status);
    }

    public static function resolveStatus(?Variable $arg): int
    {
        if (null === $arg) {
            return 0;
        }
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $v->type) {
            echo $v->toString();

            return 0;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            return $v->toInt();
        }

        throw self::typeErrorForStatus($v);
    }

    private static function typeErrorForStatus(Variable $value): \TypeError
    {
        return new \TypeError(sprintf(
            'exit(): Argument #1 ($status) must be of type string|int, %s given',
            self::statusTypeName($value)
        ));
    }

    private static function statusTypeName(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type) {
            return $value->toObject()->class->name;
        }

        return TypeCheck::typeNameForConstraint($value->type);
    }
}
