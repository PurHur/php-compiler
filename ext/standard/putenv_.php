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
 * Soft-null on PROFILE=8.4 — Zend DEP+coerce then ValueError on empty (#21312, reverts #21004 TypeError).
 */
final class putenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('putenv');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#28690).
        $this->requireExactArgCount($frame, 'putenv', 1);
        // Soft-null — Zend Z_PARAM_STR DEP+coerce; empty → ValueError (#21312).
        $assignment = VmString::trimFamilyStringArgForFrame($frame, 0, 'putenv', 0, 'assignment');
        $ok = VmEnv::putenv($assignment);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28690.
        if (!$this->requireExactJitArgCount($context, $args, 'putenv', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
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
        // Null under caller strict_types: TypeError without materialize+putenv
        // (AOT IR clears insert block on abort; peer ini_get #20361 / bindec #20658).
        $nullArg = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        if ($nullArg && $context->callerStrictTypes) {
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

    /** Soft-null Z_PARAM_STR on 8.4 — Zend DEP+coerce (#21312); strict_types still TypeError. */
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

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'putenv',
            0,
            'assignment'
        );
    }
}
