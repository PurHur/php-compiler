<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrtok;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strtok() — tokenize strings with static continuation state (php-src ext/standard/string.c; #3201, #27645).
 */
final class strtok extends Internal
{
    public function __construct()
    {
        parent::__construct('strtok');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#30703).
        $this->requireArgCountRange($frame, 'strtok', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR $string — caller strict_types → TypeError on null; else soft-null (#29784 / #21195).
        if (InternalStrictArg::isCallerStrict($frame)) {
            $str = VmString::trimFamilyStringArgForFrame($frame, 0, 'strtok', 0, 'string');
        } else {
            $str = VmString::coerceStrtokStringArg($frame->calledArgs[0]);
        }
        $tok = null;
        if (2 === $argc) {
            $tok = VmString::coerceStrtokTokenArg($frame->calledArgs[1]);
        }
        $result = VmString::strtok($str, $tok);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #30703.
        if (!$this->requireArgCountRangeJit($context, $args, 'strtok', 1, 2)) {
            return JitStrtok::deadFalseResult($context);
        }
        $argc = \count($args);

        // Early TypeError return before StringStrtok::ensureLinked (AOT helper IR gap; #19242 / #29784).
        if (
            $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strtok', 0, 'string');

            return JitStrtok::deadFalseResult($context);
        }

        if (1 === $argc) {
            StringStrtok::ensureLinked($context);

            // One-arg: stub names the operand $string (Reflection); soft-null outside strict (#29784).
            $token = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strtok', 0, 'string')
                : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strtok', 0, 'string');

            return JitStrtok::tokenize(
                $context,
                null,
                $token
            );
        }

        StringStrtok::ensureLinked($context);
        // Z_PARAM_STR_OR_NULL — preserve null so VmString::strtok one-arg mode matches php-src (#25171).
        $tok = JitStringBuiltinArg::lowerNullableString(
            $context,
            $args[1],
            'strtok',
            1,
            'token'
        );

        $hay = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strtok', 0, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strtok', 0, 'string');

        return JitStrtok::tokenize(
            $context,
            $hay,
            $tok
        );
    }

}
