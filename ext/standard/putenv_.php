<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * putenv() — set/unset process environment (VM; JIT/AOT via GetenvJitHelper + libc mirror).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(putenv) / Z_PARAM_STR
 * Null → TypeError on 8.4 forward profile (#21004, re-#17041) before syntax ValueError.
 */
final class putenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('putenv');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('putenv() requires exactly one argument');
        }
        // Z_PARAM_STR — TypeError on PROFILE=8.4 before empty-assignment ValueError (#21004).
        $assignment = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'putenv', 0, 'assignment');
        $ok = VmEnv::putenv($assignment);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('putenv() requires exactly one argument');
        }
        // Compile-time "NAME=value" when CTS is trustworthy. Slot-backed concat temps may
        // carry partial CTS like "REQUEST_BODY=" (empty value) after `$body = …; putenv(…)`,
        // which setenv's an empty REQUEST_BODY and breaks multipart AOT (#5965).
        $literal = \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal && '' !== $literal) {
            $eqPos = strpos($literal, '=');
            if (false !== $eqPos && 0 !== $eqPos) {
                $valueLen = \strlen($literal) - $eqPos - 1;
                $slotPartial = JITVariable::KIND_VARIABLE === $args[0]->kind && 0 === $valueLen;
                if (!$slotPartial) {
                    return JitEnv::putenvFromCStringLiteral($context, $literal);
                }
            }
        }
        // Null operand: TypeError under PROFILE=8.4 / strict_types without materialize+putenv
        // (AOT IR clears insert block on abort; peer ini_get #20361 / bindec #20658 / #21004).
        $nullArg = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        if ($nullArg && (
            $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile()
        )) {
            // lowerRequiredString: branch to err block before TypeErrorRaise NestedJIT
            // so uncaught AOT keeps a parent function (#20361 pattern).
            JitStringBuiltinArg::lowerRequiredString(
                $context,
                $args[0],
                'putenv',
                0,
                'assignment'
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $assignment = self::jitAssignmentArg($context, $args[0]);
        // Dominating __string__* for concat/slot temps (syntax guard + setenv mirror) (#17316).
        $assignment = \PHPCompiler\JIT\JitStringArg::materializeStringDominating($context, $assignment);

        return JitEnv::putenv($context, $assignment);
    }

    /** Z_PARAM_STR — null TypeError on 8.4 forward profile (#21004). */
    private static function jitAssignmentArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'putenv',
                0,
                'assignment'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'putenv',
            0,
            'assignment'
        );
    }
}
