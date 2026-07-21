<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strftime() — locale time formatting via libc strftime (ext/standard/datetime.c, #3692). */
final class strftime extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('strftime() expects at least 1 argument, 0 given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError('strftime() expects at most 2 arguments, '.$argc.' given');
        }
        VmEngineBuiltinDeprecation::emitFunction($frame, 'strftime');
        if (null === $frame->returnVar) {
            return;
        }
        $format = self::vmFormatArg($frame);
        if (false === $format) {
            $frame->returnVar->bool(false);

            return;
        }
        $timestamp = null;
        if (2 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArgForFrame($frame, 1, 'strftime', 2, 'timestamp');
        }
        $result = VmDate::strftime($format, $timestamp);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDate::formatStrftime($context, false, ...$args);
    }

    /** @return string|false */
    private static function vmFormatArg(Frame $frame): string|false
    {
        // Soft-null $format → DEP + false (Zend 8.4.23; #21582, reverts #20227 TypeError).
        // Keep false (not '') for #18945 — do not coerce through Z_PARAM_STR → php_strftime("").
        // php-src: ext/standard/datetime.c — PHP_FUNCTION(strftime)
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            if (InternalStrictArg::isCallerStrict($frame)) {
                InternalStrictArg::requireString($frame, 0, 'strftime', 'format');
            }
            VmNullStringParamDeprecation::emit($frame, 'strftime', 0, 'format');

            return false;
        }

        return VmString::zparamStrBuiltinArgForFrame($frame, 0, 'strftime', 0, 'format');
    }
}
