<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time ArrayPadType lowering for array_pad() (#17240, #17600).
 */
final class ArrayPadTypeJit
{
    public static function compileTimePadType(Context $context, JITVariable $arg): ?int
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

        return VmArray::tryArrayPadTypeInt($phpVar);
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
        $arrayPadTypeId = $jitObject->arrayPadTypeEnumClassId();
        if (null === $arrayPadTypeId || $arrayPadTypeId !== $classId) {
            return null;
        }
        $canonicalName = $jitObject->enumCaseCanonicalName($classId, $caseKey);
        if ('' === $canonicalName) {
            return null;
        }

        return match ($canonicalName) {
            'Positive' => StdlibConstants::ARRAY_PAD_RIGHT,
            'Negative' => StdlibConstants::ARRAY_PAD_LEFT,
            default => null,
        };
    }
}
