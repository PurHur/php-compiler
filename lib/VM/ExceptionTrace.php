<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmDebugBacktrace;
use PHPCompiler\Func\Internal;

/**
 * Populate user exception `trace` property on throw (issue #3351; Zend zend_exceptions.c).
 */
final class ExceptionTrace
{
    /**
     * Snapshot caller frame for manual `new Throwable()` (not thrown) — Zend object_init_ex (#9905).
     */
    public static function captureOnManualConstruct(Context $ctx, Frame $constructFrame, ObjectEntry $object): void
    {
        if (!self::classHasInstanceProperty($object->class, ExceptionSupport::PROP_TRACE, $ctx)) {
            return;
        }
        $caller = $constructFrame->parent;
        if (null === $caller) {
            return;
        }
        $object->manualConstructTrace = self::sanitizeCapturedTrace(VmDebugBacktrace::build($caller));
    }

    public static function captureOnThrow(Context $ctx, Frame $frame, Variable $thrown): void
    {
        $thrown = $thrown->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            return;
        }
        $object = $thrown->toObject();
        $object->manualConstructTrace = null;
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

    /**
     * Builtin throw trace — Zend includes internal function name at user call site (#11677).
     */
    public static function captureOnBuiltinThrow(
        Context $ctx,
        Frame $callerFrame,
        Frame $handlerFrame,
        Variable $thrown,
    ): void {
        $thrown = $thrown->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            return;
        }
        $object = $thrown->toObject();
        $object->manualConstructTrace = null;
        if (!self::classHasInstanceProperty($object->class, ExceptionSupport::PROP_TRACE, $ctx)) {
            return;
        }
        $traceProp = $object->getProperty(ExceptionSupport::PROP_TRACE);
        $existing = $traceProp->resolveIndirect();
        if (Variable::TYPE_ARRAY === $existing->type && $existing->toArray()->getNumElements() > 0) {
            return;
        }
        $builtinName = '';
        if ($handlerFrame->hasHandler() && $handlerFrame->handler instanceof Internal) {
            $builtinName = $handlerFrame->handler->getName();
        }
        $trace = new Variable();
        $trace->newArray();
        $ht = $trace->toArray();
        if ('' !== $builtinName) {
            $ht->append(VmDebugBacktrace::builtinInvokeFrameEntry($callerFrame, $builtinName));
        }
        $userTrace = self::sanitizeCapturedTrace(VmDebugBacktrace::build($callerFrame));
        foreach ($userTrace->toArray()->iterate(true) as $frameVar) {
            $ht->append($frameVar);
        }
        $traceProp->duplicateFrom($trace);
    }

    public static function resolveTraceVariable(ObjectEntry $object): Variable
    {
        $trace = $object->getProperty(ExceptionSupport::PROP_TRACE)->resolveIndirect();
        if (Variable::TYPE_ARRAY === $trace->type && $trace->toArray()->getNumElements() > 0) {
            return $trace;
        }
        if (null !== $object->manualConstructTrace) {
            return $object->manualConstructTrace->resolveIndirect();
        }

        return $trace;
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
