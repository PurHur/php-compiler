<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrtr;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT/AOT helper for strtr() — runtime via StrtrJitHelper PHP (#9392). */
final class JitStrtr
{
    public static function translate(
        Context $context,
        Value $subject,
        Value $from,
        Value $to,
        ?JITVariable $subjectArg = null,
        ?JITVariable $fromArg = null,
        ?JITVariable $toArg = null
    ): Value {
        if (null !== $subjectArg && null !== $fromArg && null !== $toArg) {
            $sLit = JitStringArg::compileTimeLiteral($subjectArg);
            $fLit = JitStringArg::compileTimeLiteral($fromArg);
            $tLit = JitStringArg::compileTimeLiteral($toArg);
            if (null !== $sLit && null !== $fLit && null !== $tLit) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::strtr($sLit, $fLit, $tLit))
                );
            }
        }
        StringStrtr::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strtr'),
            $subject,
            $from,
            $to
        );
    }

    public static function translateArray(
        Context $context,
        Value $subject,
        Value $replacePairs,
        ?JITVariable $subjectArg = null,
        ?JITVariable $pairsArg = null
    ): Value {
        if (null !== $subjectArg && null !== $pairsArg) {
            $sLit = JitStringArg::compileTimeLiteral($subjectArg);
            $pairsLit = self::compileTimePairs($pairsArg);
            if (null !== $sLit && null !== $pairsLit) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::strtrArray($sLit, $pairsLit))
                );
            }
        }
        StringStrtr::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strtr_array'),
            $subject,
            $replacePairs
        );
    }

    /**
     * @return array<string, string>|null
     */
    private static function compileTimePairs(JITVariable $arg): ?array
    {
        if (0 === ($arg->type & JITVariable::IS_NATIVE_ARRAY) || !\is_array($arg->compileTimeArray)) {
            return null;
        }
        $pairs = [];
        foreach ($arg->compileTimeArray as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                return null;
            }
            $pairs[$key] = $value;
        }

        return $pairs;
    }
}
