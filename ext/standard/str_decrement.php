<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrIncdec;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * str_decrement() — PHP 8.3 alphanumeric string decrement (issue #3102).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_decrement) / Z_PARAM_STR
 * Null → TypeError on 8.4 forward profile (#21005); empty string → ValueError.
 */
final class str_decrement extends Internal
{
    public function __construct()
    {
        parent::__construct('str_decrement');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_decrement() requires exactly one argument in this compiler build');
        }
        $input = self::vmStringArg($frame);
        $result = VmString::strDecrement($input);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('str_decrement() requires exactly one argument in this compiler build');
        }

        $input = self::jitStringArg($context, $args[0]);

        return StringStrIncdec::invokeDecrement($context, $input);
    }

    /** Z_PARAM_STR — null TypeError on 8.4 forward profile (#21005, ext/standard/string.c). */
    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'str_decrement', 'string')->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'str_decrement',
            0,
            'string'
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'str_decrement',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'str_decrement',
            0,
            'string'
        );
    }
}
