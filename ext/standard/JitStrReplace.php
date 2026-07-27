<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helper for str_replace() (#23912).
 *
 * Case-sensitive: implode(replace, explode(search, subject)) — both builtins are green
 * under user-script AOT. NestedJIT of StrReplaceJitHelper and the old JitStringSearch
 * rebuild loop miscompile or segfault on thin AOT.
 *
 * Case-insensitive: {@see StringStrReplace} PHP helper (str_ireplace).
 *
 * php-src: ext/standard/string.c — php_str_replace
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringExplode;
use PHPCompiler\JIT\Builtin\StringStrReplace;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrReplace
{
    private static int $blockSerial = 0;

    public static function replace(
        Context $context,
        Value $search,
        Value $replace,
        Value $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): Value {
        if ($caseInsensitive) {
            return StringStrReplace::invoke(
                $context,
                $search,
                $replace,
                $subject,
                true,
                $countSlot
            );
        }

        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $searchLen = $context->builder->load(
            $context->builder->structGep($search, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);

        // Empty $search → subject unchanged, count 0 (php-src php_str_replace).
        $emptySearch = BasicBlockHelper::append($context, 'str_replace_empty_'.$id);
        $work = BasicBlockHelper::append($context, 'str_replace_explode_'.$id);
        $done = BasicBlockHelper::append($context, 'str_replace_done_'.$id);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $strPtrTy);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $searchLen, $zero);
        $context->builder->branchIf($isEmpty, $emptySearch, $work);

        $context->builder->positionAtEnd($emptySearch);
        $context->builder->store($subject, $resultSlot);
        if (null !== $countSlot) {
            $context->builder->store($zero, $countSlot);
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($work);
        $parts = StringExplode::invoke(
            $context,
            $search,
            $subject,
            $i64->constInt(\PHP_INT_MAX, false)
        );
        $joined = JitImplode::implode($context, $replace, $parts);
        $context->builder->store($joined, $resultSlot);
        if (null !== $countSlot) {
            // Replacements = max(0, num_parts - 1).
            $num = $context->builder->call(
                $context->lookupFunction('__hashtable__getNumElements'),
                $parts
            );
            $numI64 = $context->builder->zExt($num, $i64);
            $one = $i64->constInt(1, false);
            $sub = $context->builder->sub($numI64, $one);
            $neg = $context->builder->icmp(Builder::INT_SLT, $sub, $zero);
            $count = $context->builder->select($neg, $zero, $sub);
            $context->builder->store($count, $countSlot);
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }
}
