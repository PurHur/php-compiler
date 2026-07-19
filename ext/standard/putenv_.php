<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** putenv() — set/unset process environment (VM; JIT/AOT via GetenvJitHelper + libc mirror). */
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
        $assignment = VmString::stringBuiltinArgForFrame($frame, 0, 'putenv', 0, 'assignment');
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
        $assignment = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[0],
            'putenv',
            0,
            'assignment'
        );
        // Dominating __string__* for concat/slot temps (syntax guard + setenv mirror) (#17316).
        $assignment = \PHPCompiler\JIT\JitStringArg::materializeStringDominating($context, $assignment);

        return JitEnv::putenv($context, $assignment);
    }
}
