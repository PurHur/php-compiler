<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmRoundMode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time RoundingMode lowering for round() (#5934).
 */
final class RoundingModeJit
{
    public static function compileTimeRoundMode(Context $context, JITVariable $arg): ?int
    {
        $fromJitEnum = self::compileTimeJitEnumCaseInt($context, $arg);
        if (null !== $fromJitEnum) {
            return $fromJitEnum;
        }
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }

        return VmRoundMode::tryRoundModeInt($phpVar);
    }

    private static function compileTimeJitEnumCaseInt(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeEnumCase) {
            return null;
        }
        $jitObject = $context->type->object;
        if (!$jitObject instanceof \PHPCompiler\JIT\Builtin\Type\Object_) {
            return null;
        }
        $classId = $arg->compileTimeEnumCase['classId'];
        $caseKey = $arg->compileTimeEnumCase['caseKey'];
        $roundingModeId = $jitObject->roundingModeEnumClassId();
        if (null === $roundingModeId || $roundingModeId !== $classId) {
            return null;
        }
        $canonicalName = $jitObject->enumCaseCanonicalName($classId, $caseKey);
        if ('' === $canonicalName) {
            return null;
        }

        return VmRoundMode::roundModeIntFromCaseName($canonicalName);
    }
}
