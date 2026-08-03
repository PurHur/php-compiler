<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * Compile-time preg_replace / preg_filter for literal args (#27181).
 *
 * Thin AOT NestedJIT cannot return durable `__string__*` from replace helpers
 * (NUL payloads / sticky subject). When pattern/replacement/subject are
 * compile-time literals, evaluate via host Zend and emit constants.
 *
 * Prefer {@see JitJsonEncodeCompileTime} for `json_encode(preg_filter(…))` —
 * value-box returns are not yet durable under thin AOT json export.
 */
final class JitPregReplaceCompileTime
{
    public static function tryFoldReplaceString(
        Context $context,
        JITVariable $patternArg,
        JITVariable $replacementArg,
        JITVariable $subjectArg,
        ?int $limit
    ): ?Value {
        $pattern = JitStringBuiltinArg::compileTimeLiteral($patternArg)
            ?? $patternArg->compileTimeString;
        $replacement = JitStringArg::compileTimeLiteral($replacementArg)
            ?? $replacementArg->compileTimeString;
        $subject = JitStringBuiltinArg::compileTimeLiteral($subjectArg)
            ?? $subjectArg->compileTimeString;
        if (null === $pattern || null === $replacement || null === $subject) {
            return null;
        }
        $lim = $limit ?? -1;
        $result = \preg_replace($pattern, $replacement, $subject, $lim);
        if (false === $result || !\is_string($result)) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

            return $ptr;
        }

        return self::boxString($context, $result);
    }

    public static function tryFoldFilterString(
        Context $context,
        JITVariable $patternArg,
        JITVariable $replacementArg,
        JITVariable $subjectArg,
        ?int $limit
    ): ?Value {
        $pattern = JitStringBuiltinArg::compileTimeLiteral($patternArg)
            ?? $patternArg->compileTimeString;
        $replacement = JitStringArg::compileTimeLiteral($replacementArg)
            ?? $replacementArg->compileTimeString;
        $subject = JitStringBuiltinArg::compileTimeLiteral($subjectArg)
            ?? $subjectArg->compileTimeString;
        if (null === $pattern || null === $replacement || null === $subject) {
            return null;
        }
        $lim = $limit ?? -1;
        $result = \preg_filter($pattern, $replacement, $subject, $lim);
        if (false === $result) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }
        if (null === $result) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

            return $ptr;
        }

        return self::boxString($context, (string) $result);
    }

    /**
     * @return Value|null boxed hashtable, or null if not foldable
     */
    public static function tryFoldFilterArray(
        Context $context,
        JITVariable $patternArg,
        JITVariable $replacementArg,
        JITVariable $subjectArg,
        ?int $limit
    ): ?Value {
        $pattern = JitStringBuiltinArg::compileTimeLiteral($patternArg)
            ?? $patternArg->compileTimeString;
        $replacement = JitStringArg::compileTimeLiteral($replacementArg)
            ?? $replacementArg->compileTimeString;
        if (null === $pattern || null === $replacement) {
            return null;
        }
        $host = self::tryCompileTimeStringList($subjectArg);
        if (null === $host) {
            return null;
        }
        $lim = $limit ?? -1;
        $result = \preg_filter($pattern, $replacement, $host, $lim);
        if (false === $result) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }
        if (!\is_array($result)) {
            return null;
        }
        $ht = new HashTable();
        foreach ($result as $key => $line) {
            $keyVar = new VmVariable();
            if (\is_int($key)) {
                $keyVar->int($key);
            } else {
                $keyVar->string((string) $key);
            }
            $value = new VmVariable();
            $value->string((string) $line);
            array_map::appendKeyedCopy($ht, $keyVar, $value);
        }
        $global = $context->constantArrayFromVmHashTable(
            'preg_filter_lit_'.md5($pattern."\0".$replacement."\0".serialize($host)."\0".$lim),
            $ht
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        // constantArrayFromVmHashTable is `__value__*` (boxed HT) — copy, do not writeHashtable (#27181).
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $ptr;
    }

    /** @return list<string>|null */
    private static function tryCompileTimeStringList(JITVariable $arg): ?array
    {
        if (\is_array($arg->compileTimeArray)) {
            $n = $arg->nextFreeElement;
            if ($n !== \count($arg->compileTimeArray)) {
                return null;
            }
            $out = [];
            for ($i = 0; $i < $n; ++$i) {
                if (!isset($arg->compileTimeArray[$i]) || !\is_string($arg->compileTimeArray[$i])) {
                    return null;
                }
                $out[$i] = $arg->compileTimeArray[$i];
            }

            return $out;
        }
        $n = $arg->nextFreeElement;
        if ($n < 0) {
            return null;
        }
        if (0 === $n) {
            return ($arg->compileTimeEmptyArrayLiteral ?? false) ? [] : null;
        }

        return null;
    }

    /** Peer {@see JitInfo::emitUserScriptStringLiteral} — owned init + separate (#21359). */
    private static function boxString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
        $separated = $context->builder->call($context->lookupFunction('__string__separate'), $owned);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $separated);

        return $ptr;
    }
}
