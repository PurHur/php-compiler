<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayPadRuntime;
use PHPCompiler\JIT\Builtin\ArrayPadTypeJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_pad() for packed list arrays (subset of PHP; JIT via ArrayPadRuntime PHP bridge).
 *
 * php-src arity is 3 — pad direction is the sign of $length. Optional $pad_type /
 * ARRAY_PAD_* / ArrayPadType are phantoms and stay gated off (#14993, #24002).
 */
final class array_pad extends Internal
{
    public function __construct()
    {
        parent::__construct('array_pad');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $supportsPadType = CompilerVersion::supportsArrayPadPadType();
        if ($supportsPadType) {
            if ($argc < 3 || $argc > 4) {
                throw new \ArgumentCountError(\sprintf(
                    'array_pad() expects between 3 and 4 arguments, %d given',
                    $argc
                ));
            }
        } elseif (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'array_pad() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_pad', 1, 'array');
        $lengthInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_pad', 2, 'length');
        $value = $frame->calledArgs[2]->resolveIndirect();
        $padType = null;
        if (4 === $argc) {
            $padType = VmArray::resolvePadTypeArg($frame->calledArgs[3]);
        }
        $frame->returnVar->array(
            VmArray::pad($ht, $lengthInt, $value, $padType)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $supportsPadType = CompilerVersion::supportsArrayPadPadType();
        if ($supportsPadType) {
            if ($argc < 3 || $argc > 4) {
                throw new \ArgumentCountError(\sprintf(
                    'array_pad() expects between 3 and 4 arguments, %d given',
                    $argc
                ));
            }
        } elseif (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'array_pad() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        ExceptionBridge::ensureLinked($context);
        TypeErrorRaise::ensureLinked($context);
        // php-src Z_PARAM_ARRAY — catchable TypeError under AOT try/catch (#27485).
        // Always via JitArrayElem → ExceptionBridge (not bare TypeErrorRaise::emitRaise).
        JitArrayElem::requireArrayParam($context, $args[0], 'array_pad', 1, 'array');
        if (!($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
            && JITVariable::TYPE_HASHTABLE !== $args[0]->type
            && JITVariable::TYPE_VALUE !== $args[0]->type
        ) {
            // Static non-array types already raised above; poison return for SSA.
            return HashTableHelper::emptyVariable($context)->value;
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_pad() argument #'.((int) $i + 1));
            }
        }
        $length = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'array_pad', 2, 'length');
        if (4 === $argc) {
            $padTypeLiteral = ArrayPadTypeJit::compileTimePadType($context, $args[3]);
            if (null !== $padTypeLiteral) {
                $padType = $context->getTypeFromString('int64')->constInt($padTypeLiteral, false);
            } else {
                $padType = JitLongArg::lower($context, $args[3], 'array_pad() pad type');
            }

            return ArrayPadRuntime::padWithType($context, $args[0], $length, $args[2], $padType);
        }

        return ArrayPadRuntime::pad($context, $args[0], $length, $args[2]);
    }
}
