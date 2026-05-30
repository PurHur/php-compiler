<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmDebugBacktrace;

/**
 * Populate user exception `trace` property on throw (issue #3351; Zend zend_exceptions.c).
 */
final class ExceptionTrace
{
    public static function captureOnThrow(Frame $frame, Variable $thrown): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $thrown = $thrown->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            return;
        }
        $object = $thrown->toObject();
        if (!self::classHasInstanceProperty($object->class, 'trace', $frame->vmContext)) {
            return;
        }
        $object->getProperty('trace')->copyFrom(
            VmDebugBacktrace::build($frame, true)
        );
    }

    private static function classHasInstanceProperty(ClassEntry $class, string $name, Context $ctx): bool
    {
        $lcName = strtolower($name);
        $currentLc = strtolower($class->name);
        $visited = [];
        while (!isset($visited[$currentLc])) {
            $visited[$currentLc] = true;
            if (!isset($ctx->classes[$currentLc])) {
                break;
            }
            $current = $ctx->classes[$currentLc];
            foreach ($current->properties as $property) {
                if (strtolower($property->name) === $lcName) {
                    return true;
                }
            }
            if (null === $current->parentLc) {
                break;
            }
            $currentLc = $current->parentLc;
        }

        return false;
    }
}
