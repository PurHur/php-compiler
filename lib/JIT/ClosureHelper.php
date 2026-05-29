<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Minimal JIT lowering for anonymous closures without use() (issue #72).
 *
 * Compiles the closure CFG as a native function and wraps the result in a Closure
 * object whose {@see Variable::$closureCall} proxy handles direct / __invoke calls.
 */
final class ClosureHelper
{
    private static int $counter = 0;

    public static function nextInternalName(): string
    {
        return '{closure}_'.(++self::$counter);
    }

    public static function resolveCall(Variable $receiver): ?Call
    {
        return $receiver->closureCall;
    }

    public static function allocateClosureObject(Context $context, Call $callProxy): Variable
    {
        $classId = $context->type->object->lookup('Closure');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->closureCall = $callProxy;

        return $var;
    }
}
