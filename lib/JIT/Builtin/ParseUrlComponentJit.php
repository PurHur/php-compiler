<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmParseUrl;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time ParseUrl lowering for parse_url() component (#7260).
 */
final class ParseUrlComponentJit
{
    public static function compileTimeComponentInt(Context $context, JITVariable $arg): ?int
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
        $fromEnum = VmParseUrl::tryParseUrlComponentInt($phpVar);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
            return VmParseUrl::componentFromBacking($phpVar->toInt());
        }

        return null;
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
        if ('parseurl' !== strtolower(ltrim($jitObject->classNameForId($classId), '\\'))) {
            return null;
        }
        $backing = $jitObject->enumCaseBackingScalarForCase($classId, $caseKey);
        if (!\is_int($backing)) {
            return null;
        }

        return VmParseUrl::componentFromBacking($backing);
    }
}
