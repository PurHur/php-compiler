<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * Compile-time preg_split for literal args (#27208).
 *
 * Thin AOT NestedJIT string-slot / HT fill for split is unreliable (OOM or empty).
 * When pattern/subject(/limit/flags) are compile-time literals, evaluate via host Zend
 * and emit a boxed constant hashtable (peer {@see JitPregReplaceCompileTime} #27181).
 *
 * php-src: ext/pcre/php_pcre.c — PHP_FUNCTION(preg_split)
 */
final class JitPregSplitCompileTime
{
    /**
     * @return Value|null boxed array|false, or null if not foldable
     */
    public static function tryFold(
        Context $context,
        JITVariable $patternArg,
        JITVariable $subjectArg,
        ?int $limit,
        ?int $flags
    ): ?Value {
        $pattern = JitStringBuiltinArg::compileTimeLiteral($patternArg)
            ?? $patternArg->compileTimeString;
        $subject = JitStringBuiltinArg::compileTimeLiteral($subjectArg)
            ?? $subjectArg->compileTimeString;
        if (null === $pattern || null === $subject) {
            return null;
        }
        $lim = $limit ?? -1;
        $flg = $flags ?? 0;
        // Use VmPreg (not host @preg_split) — no Zend warning noise; matches VM (#27208).
        $parts = VmPreg::pregSplit($pattern, $subject, $lim, $flg);
        if (false === $parts) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }
        if (!\is_array($parts)) {
            return null;
        }
        $ht = new HashTable();
        foreach ($parts as $key => $part) {
            $keyVar = new VmVariable();
            if (\is_int($key)) {
                $keyVar->int($key);
            } else {
                $keyVar->string((string) $key);
            }
            $value = new VmVariable();
            if (\is_array($part)) {
                // PREG_SPLIT_OFFSET_CAPTURE — [string, offset]
                $inner = new HashTable();
                $s = new VmVariable();
                $s->string((string) ($part[0] ?? ''));
                $o = new VmVariable();
                $o->int((int) ($part[1] ?? 0));
                $k0 = new VmVariable();
                $k0->int(0);
                $k1 = new VmVariable();
                $k1->int(1);
                array_map::appendKeyedCopy($inner, $k0, $s);
                array_map::appendKeyedCopy($inner, $k1, $o);
                $value->array($inner);
            } else {
                $value->string((string) $part);
            }
            array_map::appendKeyedCopy($ht, $keyVar, $value);
        }
        $global = $context->constantArrayFromVmHashTable(
            'preg_split_lit_'.md5($pattern."\0".$subject."\0".$lim."\0".$flg),
            $ht
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        // Boxed HT copy — do not writeHashtable (#27181 / #27080 empty-string trap).
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $ptr;
    }
}
