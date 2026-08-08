<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmDebugBacktrace;
use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * Populate user exception `trace` property on throw (issue #3351; Zend zend_exceptions.c).
 */
final class ExceptionTrace
{
    /**
     * Builtins Zend lowers as opcodes — TypeError has no internal call frame (#28852).
     *
     * @var array<string, true>
     */
    private const OPCODE_TYPEERROR_OMIT_BUILTINS = [
        'strlen' => true,
        'count' => true,
        'sizeof' => true,
        'get_class' => true,
    ];

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
        $object->manualConstructTrace = self::sanitizeCapturedTrace(
            VmDebugBacktrace::build($caller, self::traceCaptureOptions())
        );
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
        $opts = self::traceCaptureOptions();
        $built = VmDebugBacktrace::build($frame, $opts);
        if (0 === $built->toArray()->getNumElements()) {
            // #14369 / #14132: bridge throws (return-type TypeError) run off runStack — anchor throw-site frame.
            $built = VmDebugBacktrace::buildFromFrames([$frame], $opts);
        }
        $traceProp->duplicateFrom(self::sanitizeCapturedTrace($built));
    }

    /**
     * Warning stack for engine-invoked `__debugInfo` throw under var_dump/print_r/debug_zval_dump (#28618).
     *
     * php-src: zend_call_method → `[internal function]: Class->__debugInfo()`, then the dump builtin
     * at the user call site (Zend/zend_object_handlers.c + ext/standard/var.c).
     */
    public static function buildDebugInfoEngineInvokeTrace(ObjectEntry $object, ?Frame $dumpFrame): Variable
    {
        $trace = new Variable();
        $trace->newArray();
        $ht = $trace->toArray();

        $hook = new Variable();
        $hook->newArray();
        $hookHt = $hook->toArray();
        $classVar = new Variable(Variable::TYPE_STRING);
        $classVar->string($object->class->name);
        $hookHt->add('class', $classVar);
        $typeVar = new Variable(Variable::TYPE_STRING);
        $typeVar->string('->');
        $hookHt->add('type', $typeVar);
        $fnVar = new Variable(Variable::TYPE_STRING);
        $fnVar->string('__debugInfo');
        $hookHt->add('function', $fnVar);
        $ht->append($hook);

        if (null === $dumpFrame) {
            return $trace;
        }

        $builtinName = '';
        if ($dumpFrame->hasHandler() && $dumpFrame->handler instanceof Internal) {
            $builtinName = $dumpFrame->handler->getName();
        }
        $caller = $dumpFrame->parent;
        if ('' === $builtinName || null === $caller) {
            return $trace;
        }

        // Builtin call site = caller's opline (callSiteLine), not parent-of-caller (#28618 nested).
        $ht->append(self::debugInfoDumpBuiltinFrameEntry($caller, $builtinName));

        $chain = [];
        for ($f = $caller; null !== $f; $f = $f->parent) {
            if ($f->hasHandler()) {
                continue;
            }
            if (null !== $f->block && $f->block->isMainScript()) {
                break;
            }
            $chain[] = $f;
        }
        if ([] !== $chain) {
            $rest = self::sanitizeCapturedTrace(
                VmDebugBacktrace::buildFromFrames($chain, self::traceCaptureOptions())
            );
            foreach ($rest->toArray()->iterate(true) as $frameVar) {
                $ht->append($frameVar);
            }
        }

        return $trace;
    }

    /**
     * Frame entry for the dump builtin at the user call site (file/line of the call inside the caller).
     */
    private static function debugInfoDumpBuiltinFrameEntry(Frame $caller, string $builtinName): Variable
    {
        $entry = new Variable();
        $entry->newArray();
        $ht = $entry->toArray();

        [$file, $line] = ExceptionSupport::userFatalSite($caller);
        if ('' === $file && $caller->callSiteLine > 0) {
            $line = $caller->callSiteLine;
        } elseif ($caller->callSiteLine > 0) {
            // Prefer the active call opcode line over parent-invoke line (#28618).
            $line = $caller->callSiteLine;
        }
        if ('' !== $file) {
            $fileVar = new Variable(Variable::TYPE_STRING);
            $fileVar->string($file);
            $ht->add('file', $fileVar);

            $lineVar = new Variable(Variable::TYPE_INTEGER);
            $lineVar->int(max(0, $line));
            $ht->add('line', $lineVar);
        }

        $fnVar = new Variable(Variable::TYPE_STRING);
        $fnVar->string($builtinName);
        $ht->add('function', $fnVar);

        return $entry;
    }

    /**
     * Builtin throw trace — Zend includes internal function name at user call site (#11677).
     *
     * Exception: ZEND_STRLEN / ZEND_COUNT / ZEND_GET_CLASS TypeErrors have no internal call
     * frame in php-src (opcode handlers throw with the user execute_data). Omit the synthetic
     * builtin row so getTrace()[0]['function'] is the caller (#28852). ArgumentCountError still
     * keeps the builtin frame (Zend does for strlen() argc).
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
        $omitBuiltin = self::omitOpcodeBuiltinTypeErrorFrame($object, $builtinName);
        if ('' !== $builtinName && !$omitBuiltin) {
            $ht->append(VmDebugBacktrace::builtinInvokeFrameEntry($callerFrame, $builtinName));
        }
        $opts = self::traceCaptureOptions();
        $userTrace = self::sanitizeCapturedTrace(VmDebugBacktrace::build($callerFrame, $opts));
        // build() treats a direct user frame under {main} as top-level and returns [].
        // Fall back so the caller appears (Zend argc: strlen + user; opcode TypeError: user only).
        // Skip main — Zend bare opcode TypeError has an empty getTrace() (#28852).
        if (
            0 === $userTrace->toArray()->getNumElements()
            && !(null !== $callerFrame->block && $callerFrame->block->isMainScript())
        ) {
            $userTrace = self::sanitizeCapturedTrace(
                VmDebugBacktrace::buildFromFrames([$callerFrame], $opts)
            );
        }
        foreach ($userTrace->toArray()->iterate(true) as $frameVar) {
            $ht->append($frameVar);
        }
        $traceProp->duplicateFrom($trace);
    }

    /**
     * php-src specialized opcodes throw TypeError without an internal execute_data frame.
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_vm_def.h ZEND_STRLEN / ZEND_COUNT / ZEND_GET_CLASS
     */
    private static function omitOpcodeBuiltinTypeErrorFrame(ObjectEntry $object, string $builtinName): bool
    {
        if ('' === $builtinName) {
            return false;
        }
        $lcBuiltin = strtolower($builtinName);
        if (!isset(self::OPCODE_TYPEERROR_OMIT_BUILTINS[$lcBuiltin])) {
            return false;
        }
        // ArgumentCountError extends TypeError — keep its builtin frame (Zend strlen() argc).
        return 'typeerror' === strtolower($object->class->name);
    }

    /** Generator throw-site snapshot when debug_backtrace cannot see isolated stack (#13418). */
    public static function captureGeneratorThrowSite(Context $ctx, Frame $frame, Variable $thrown): void
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
        $traceProp->duplicateFrom(self::sanitizeCapturedTrace(self::generatorThrowFrameTrace($frame)));
    }

    /** Append Generator::{next,send,...} after throw-site frames (Zend zend_generators.c, #13418). */
    public static function captureOnGeneratorResumeUncaught(
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
        $resumeFrame = self::classMethodBuiltinFrameEntry($callerFrame, $handlerFrame);
        $traceProp = $object->getProperty(ExceptionSupport::PROP_TRACE);
        $existing = $traceProp->resolveIndirect();
        $merged = new Variable();
        $merged->newArray();
        $mergedHt = $merged->toArray();
        if (Variable::TYPE_ARRAY === $existing->type) {
            foreach (self::sanitizeCapturedTrace($existing)->toArray()->iterate(true) as $frameVar) {
                $mergedHt->append($frameVar);
            }
        }
        $mergedHt->append($resumeFrame);
        $traceProp->duplicateFrom($merged);
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

    /**
     * php-src EG(exception_ignore_args) — omit trace[].args when On (#21998).
     * When Off, include args with #[\SensitiveParameter] redaction (#21339 / re-#15036).
     */
    private static function traceCaptureOptions(): int
    {
        return VmIni::exceptionIgnoreArgsEnabled() ? VmDebugBacktrace::IGNORE_ARGS : 0;
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
            if (Variable::TYPE_STRING === $fn->type) {
                $fnName = $fn->toString();
                // `{main}` is synthesized in getTraceAsString(); empty names are not Zend frames.
                if ('{main}' === $fnName || '' === $fnName) {
                    continue;
                }
            }
            $copy = new Variable();
            $copy->duplicateFrom($frameVar);
            $outHt->append($copy);
        }

        return $out;
    }

    private static function classMethodBuiltinFrameEntry(Frame $callerFrame, Frame $handlerFrame): Variable
    {
        $methodName = '';
        if ($handlerFrame->hasHandler()) {
            $methodName = $handlerFrame->handler->getName();
        }
        $className = '';
        if ($handlerFrame->handler instanceof VmClassMethod && !empty($handlerFrame->calledArgs)) {
            $receiver = $handlerFrame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $receiver->type) {
                $className = $receiver->toObject()->class->name ?? '';
            }
        }

        return VmDebugBacktrace::builtinInvokeFrameEntry($callerFrame, $methodName, $className, '->');
    }

    private static function generatorThrowFrameTrace(Frame $frame): Variable
    {
        $trace = new Variable();
        $trace->newArray();
        if (null === $frame->block || null === $frame->block->func) {
            return $trace;
        }
        $trace->toArray()->append(
            VmDebugBacktrace::internalFunctionFrameEntry($frame->block->func->name)
        );

        return $trace;
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
