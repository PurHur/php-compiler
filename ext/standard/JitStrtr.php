<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\StringStrtr;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
            $sLit = self::compileTimeStringOrNullCoerced($subjectArg);
            $fLit = self::compileTimeStringOrNullCoerced($fromArg);
            $tLit = self::compileTimeStringOrNullCoerced($toArg);
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

    /**
     * Compile-time string literal, or '' for null constants (Z_PARAM_STR soft-null, #29308).
     *
     * Soft-null lowers emit E_DEPRECATED then an empty string Value; folding here keeps
     * constant null args off the NestedJIT empty-$from hang path in __compiler_strtr.
     */
    private static function compileTimeStringOrNullCoerced(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return '';
        }

        return JitStringArg::compileTimeLiteral($arg);
    }

    public static function translateArray(
        Context $context,
        JITVariable $subjectArg,
        JITVariable $pairsArg
    ): Value {
        $pairsLit = self::compileTimePairs($pairsArg);
        if (null !== $pairsLit) {
            $sLit = JitStringArg::compileTimeLiteral($subjectArg);
            if (null !== $sLit) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::strtrArray($sLit, $pairsLit))
                );
            }
        }

        $subject = JitStringBuiltinArg::lowerZparamStr($context, $subjectArg, 'strtr', 0, 'string');
        BasicBlockHelper::ensureOpenInsertBlock($context, 'strtr_array_subject_cont');
        $replacePairs = ArrayBuiltinHelper::loadHashTable($context, $pairsArg);
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
        if (0 === ($arg->type & JITVariable::IS_NATIVE_ARRAY) || !\is_array($arg->compileTimeArray ?? null)) {
            return null;
        }
        $pairs = [];
        foreach ($arg->compileTimeArray as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                return null;
            }
            // Empty from-key must run at runtime so E_WARNING reaches handlers (#26704).
            if ('' === $key) {
                return null;
            }
            $pairs[$key] = $value;
        }

        return $pairs;
    }
}
