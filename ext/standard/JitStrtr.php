<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\StringStrtr;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
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
        JITVariable $subjectArg,
        JITVariable $pairsArg
    ): Value {
        $pairsLit = self::compileTimePairs($pairsArg);
        if (null !== $pairsLit) {
            $sLit = JitStringArg::compileTimeLiteral($subjectArg);
            if (null !== $sLit) {
                // Literal empty from-key: emit E_WARNING in the binary, then fold the rest
                // (#26704). Avoids host-side warn during compile and the runtime HT path.
                if (\array_key_exists('', $pairsLit)) {
                    self::emitEmptyReplacementWarning($context);
                    unset($pairsLit['']);
                }

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
     * Emit php-src empty-key E_WARNING for compile-time replace_pairs (#26704).
     */
    private static function emitEmptyReplacementWarning(Context $context): void
    {
        StringTriggerError::ensureLinked($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msg = $context->builder->pointerCast(
            $context->constantFromString(VmString::STRTR_EMPTY_REPLACEMENT_WARNING),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(max(0, $context->callSiteLine), false)
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
