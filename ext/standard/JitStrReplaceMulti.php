<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for str_replace() with array $search and/or array|string $replace (#11056). */
final class JitStrReplaceMulti
{
    public static function replace(
        Context $context,
        JITVariable $searchArg,
        JITVariable $replaceArg,
        JITVariable $subjectArg,
        ?Value $countSlot = null
    ): Value {
        $searchNeedles = self::compileTimeNeedleList($searchArg);
        $replaceOperand = self::compileTimeReplaceOperand($replaceArg);
        $subjectLit = JitStringArg::compileTimeLiteral($subjectArg);
        if (null !== $searchNeedles && null !== $replaceOperand && null !== $subjectLit) {
            $count = 0;
            $result = 1 === \count($searchNeedles) && \is_string($replaceOperand)
                ? VmString::strReplace($searchNeedles[0], $replaceOperand, $subjectLit, $count)
                : VmString::strReplaceMulti($searchNeedles, $replaceOperand, $subjectLit, $count);
            if (null !== $countSlot) {
                $i64 = $context->getTypeFromString('int64');
                $context->builder->store($i64->constInt($count, false), $countSlot);
            }

            return $context->builder->load($context->constantStringFromString($result));
        }

        if (self::isRuntimeArrayArg($searchArg) || self::isRuntimeArrayArg($replaceArg)) {
            throw new \LogicException(
                'str_replace() runtime array $search/$replace is not supported in this compiler build'
            );
        }

        return JitStrReplace::replace(
            $context,
            JitStringArg::lower($context, $searchArg, 'str_replace() search'),
            JitStringArg::lower($context, $replaceArg, 'str_replace() replace'),
            JitStringArg::lower($context, $subjectArg, 'str_replace() subject'),
            false,
            $countSlot
        );
    }

    /**
     * @return list<string>|null
     */
    private static function compileTimeNeedleList(JITVariable $arg): ?array
    {
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY) && \is_array($arg->compileTimeArray)) {
            $needles = [];
            foreach ($arg->compileTimeArray as $value) {
                if (!\is_string($value)) {
                    return null;
                }
                $needles[] = $value;
            }

            return $needles;
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            return [$literal];
        }

        return null;
    }

    /**
     * @return list<string>|string|null
     */
    private static function compileTimeReplaceOperand(JITVariable $arg): array|string|null
    {
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY) && \is_array($arg->compileTimeArray)) {
            $values = [];
            foreach ($arg->compileTimeArray as $value) {
                if (!\is_string($value)) {
                    return null;
                }
                $values[] = $value;
            }

            return $values;
        }
        $literal = JitStringArg::compileTimeLiteral($arg);

        return null !== $literal ? $literal : null;
    }

    private static function isRuntimeArrayArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY) && !\is_array($arg->compileTimeArray)) {
            return true;
        }

        return false;
    }
}
