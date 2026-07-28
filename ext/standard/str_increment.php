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
 * str_increment() — PHP 8.3 alphanumeric string increment (issue #3102).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_increment) / Z_PARAM_STR
 * Null → deprecate+coerce then empty ValueError on 8.4 forward profile (#24179, reverts #21005).
 */
final class str_increment extends Internal
{
    public function __construct()
    {
        parent::__construct('str_increment');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_increment() requires exactly one argument in this compiler build');
        }
        $input = self::vmStringArg($frame);
        $result = VmString::strIncrement($input);
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
            throw new \LogicException('str_increment() requires exactly one argument in this compiler build');
        }

        $input = self::jitStringArg($context, $args[0]);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $input,
            'str_increment(): Argument #1 ($string) must not be empty'
        );

        return StringStrIncdec::invokeIncrement($context, $input);
    }

    /** Soft-null on forward profile — Zend 8.4 deprecate+coerce then empty ValueError (#24179). */
    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'str_increment', 'string')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'str_increment',
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
                'str_increment',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_increment',
            0,
            'string'
        );
    }
}
