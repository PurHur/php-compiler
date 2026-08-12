<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrIncdec;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * str_decrement() — PHP 8.3 alphanumeric string decrement (issue #3102).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_decrement) / Z_PARAM_STR
 * Null soft-coerces with E_DEPRECATED on PROFILE≥8.4 then empty → ValueError (#26264, re-#24179;
 * reverts over-strict TypeError from #21005). Caller strict_types still TypeErrors null.
 */
final class str_decrement extends Internal
{
    public function __construct()
    {
        parent::__construct('str_decrement');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#28679; peer #28691).
        $this->requireExactArgCount($frame, 'str_decrement', 1);
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
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28679.
        if (!$this->requireExactJitArgCount($context, $args, 'str_decrement', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        $input = self::jitStringArg($context, $args[0]);
        // Empty after soft-null (or '') → ValueError before helper (#26264; php-src string.c).
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $input,
            'str_decrement(): Argument #1 ($string) must not be empty'
        );

        return StringStrIncdec::invokeDecrement($context, $input);
    }

    /** Z_PARAM_STR — soft-null DEP+coerce on PROFILE≥8.4 (#26264; php-src string.c). */
    private static function vmStringArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame($frame, 0, 'str_decrement', 0, 'string');
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

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_decrement',
            0,
            'string'
        );
    }
}
