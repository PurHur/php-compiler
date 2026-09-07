<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrIncdec;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DiscardedPureCallElision;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * str_increment() — PHP 8.3 alphanumeric string increment (issue #3102).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_increment) / Z_PARAM_STR
 * Null soft-coerces with E_DEPRECATED on PROFILE≥8.4 then empty → ValueError (#26264, re-#24179;
 * reverts over-strict TypeError from #21005). Caller strict_types still TypeErrors null.
 */
final class str_increment extends Internal
{
    public function __construct()
    {
        parent::__construct('str_increment');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#28679; peer #28691).
        $this->requireExactArgCount($frame, 'str_increment', 1);
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
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28679.
        if (!$this->requireExactJitArgCount($context, $args, 'str_increment', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        // Proven-safe compile-time literal → immortal result (no helper / ValueError)
        // — peer intdiv fold + discarded-elision proofs (#36386 / #37168).
        $folded = self::tryFoldCompileTime($context, $args[0]);
        if (null !== $folded) {
            return $folded;
        }

        $input = self::jitStringArg($context, $args[0]);
        // Empty after soft-null (or '') → ValueError before helper (#26264; php-src string.c).
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $input,
            'str_increment(): Argument #1 ($string) must not be empty'
        );

        return StringStrIncdec::invokeIncrement($context, $input);
    }

    /**
     * Fold when {@see DiscardedPureCallElision::strIncDecArgsCannotThrow} —
     * php-src string.c cannot ValueError on this literal.
     */
    private static function tryFoldCompileTime(Context $context, JITVariable $arg): ?Value
    {
        if (!DiscardedPureCallElision::strIncDecArgsCannotThrow('str_increment', [$arg])) {
            return null;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit) {
            return null;
        }

        return $context->builder->load(
            $context->constantStringFromString(VmString::strIncrement($lit))
        );
    }

    /** Z_PARAM_STR — soft-null DEP+coerce on PROFILE≥8.4 (#26264; php-src string.c). */
    private static function vmStringArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame($frame, 0, 'str_increment', 0, 'string');
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
