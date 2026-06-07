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
    public static function captureOnThrow(Context $ctx, Frame $frame, Variable $thrown): void
    {
        $thrown = $thrown->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            return;
        }
        $object = $thrown->toObject();
        if (!self::classHasInstanceProperty($object->class, ExceptionSupport::PROP_TRACE, $ctx)) {
            return;
        }
        $traceProp = $object->getProperty(ExceptionSupport::PROP_TRACE);
        $existing = $traceProp->resolveIndirect();
        if (Variable::TYPE_ARRAY === $existing->type && $existing->toArray()->getNumElements() > 0) {
            return;
        }
        $traceProp->duplicateFrom(self::sanitizeCapturedTrace(VmDebugBacktrace::build($frame)));
    }

    /** Zend stores throw-site frames only; `{main}` is synthesized in getTraceAsString(). */
    private static function sanitizeCapturedTrace(Variable $trace): Variable
    {
        $trace = $trace->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $trace->type) {
            $empty = new Variable();
            $empty->newArray();

            return $empty;
        }
        $out = new Variable();
        $out->newArray();
        $outHt = $out->toArray();
        foreach ($trace->toArray()->iterate(true) as $frameVar) {
            $frameVar = $frameVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $frameVar->type) {
                continue;
            }
            $fnKey = new Variable(Variable::TYPE_STRING);
            $fnKey->string('function');
            $ht = $frameVar->toArray();
            if (!$ht->keyExists($fnKey)) {
                $outHt->append($frameVar);
                continue;
            }
            $fnVar = $ht->findVariable($fnKey, false);
            if (null === $fnVar) {
                $outHt->append($frameVar);
                continue;
            }
            $fn = $fnVar->resolveIndirect();
            if (Variable::TYPE_STRING === $fn->type && '{main}' === $fn->toString()) {
                continue;
            }
            $copy = new Variable();
            $copy->duplicateFrom($frameVar);
            $outHt->append($copy);
        }

        return $out;
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
