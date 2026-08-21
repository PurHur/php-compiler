<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT fscanf/sscanf array return for whitespace+%d/%s formats from libc fgets lines (#33382).
 *
 * NestedJIT {@see SscanfJitHelper} aborts on fgets→__string__init payloads (#27663); strtol+%s
 * token scan matches php-src php_sscanf_internal for this subset without NestedJIT.
 */
final class SscanfFgetsThinArray
{
    /** True when format is only whitespace + %d / %s / %% (optional length modifiers). */
    public static function supportsFormat(string $format): bool
    {
        $len = \strlen($format);
        $i = 0;
        $specs = 0;
        while ($i < $len) {
            $ch = $format[$i];
            if ('%' === $ch) {
                if ($i + 1 >= $len) {
                    return false;
                }
                ++$i;
                if ('%' === $format[$i]) {
                    ++$i;
                    continue;
                }
                while ($i < $len && (\in_array($format[$i], ['l', 'h', 'z', 't'], true))) {
                    ++$i;
                }
                if ($i >= $len || ('d' !== $format[$i] && 's' !== $format[$i])) {
                    return false;
                }
                ++$i;
                ++$specs;
                continue;
            }
            if (\ctype_space($ch)) {
                ++$i;
                continue;
            }

            return false;
        }

        return $specs > 0;
    }

    /**
     * Scan $line per $fmtLit into a boxed array value.
     *
     * @return Value __value__* (hashtable)
     */
    public static function scanToArrayBox(Context $context, Value $line, string $fmtLit): Value
    {
        LibcExtern::register($context);
        LibcExtern::ensureStrtolDecl($context);
        self::ensureWriters($context);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $stringMap = $context->structFieldMap['__string__'];

        $cursorSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $endPtrSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $data = $context->builder->pointerCast(
            $context->builder->structGep($line, $stringMap['value']),
            $i8p
        );
        $context->builder->store($data, $cursorSlot);

        $temps = [];
        $len = \strlen($fmtLit);
        $fi = 0;
        $specIdx = 0;
        while ($fi < $len) {
            $ch = $fmtLit[$fi];
            if (\ctype_space($ch) || ('%' === $ch && $fi + 1 < $len && '%' === $fmtLit[$fi + 1])) {
                $fi += ('%' === $ch) ? 2 : 1;
                continue;
            }
            if ('%' !== $ch) {
                ++$fi;
                continue;
            }
            ++$fi;
            while ($fi < $len && (\in_array($fmtLit[$fi], ['l', 'h', 'z', 't'], true))) {
                ++$fi;
            }
            if ($fi >= $len) {
                break;
            }
            $spec = $fmtLit[$fi];
            ++$fi;
            self::skipWhitespace($context, $cursorSlot, $i8, $sizeT, $specIdx);
            $box = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $box);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
            if ('d' === $spec) {
                self::scanLong($context, $cursorSlot, $endPtrSlot, $ptr, $i64, $i32, $i8p);
            } else {
                self::scanString($context, $cursorSlot, $ptr, $i8, $i8p, $sizeT, $i64);
            }
            $temps[] = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $ptr);
            ++$specIdx;
        }

        $packed = HashTableHelper::packVariables($context, $temps);
        $htPtr = HashTableHelper::loadHashtablePointer($context, $packed);
        $arrSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $arrSlot),
            $htPtr
        );

        return JitValueBox::pointer($context, $arrSlot);
    }

    private static function skipWhitespace(
        Context $context,
        Value $cursorSlot,
        $i8,
        $sizeT,
        int $specIdx
    ): void {
        $id = (string) \spl_object_id($context).'_'.$specIdx;
        $head = BasicBlockHelper::append($context, 'thin_scanf_ws_head_'.$id);
        $body = BasicBlockHelper::append($context, 'thin_scanf_ws_body_'.$id);
        $done = BasicBlockHelper::append($context, 'thin_scanf_ws_done_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $cur = $context->builder->load($cursorSlot);
        $ch = $context->builder->load($context->builder->pointerCast($cur, $i8->pointerType(0)));
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $isSpace = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x20, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x09, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0a, false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0d, false))
                )
            )
        );
        $context->builder->branchIf($isNul, $done, $body);

        $context->builder->positionAtEnd($body);
        $adv = BasicBlockHelper::append($context, 'thin_scanf_ws_adv_'.$id);
        $context->builder->branchIf($isSpace, $adv, $done);
        $context->builder->positionAtEnd($adv);
        $context->builder->store(
            $context->builder->gep($cur, $sizeT->constInt(1, false)),
            $cursorSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function scanLong(
        Context $context,
        Value $cursorSlot,
        Value $endPtrSlot,
        Value $outPtr,
        $i64,
        $i32,
        $i8p
    ): void {
        $cur = $context->builder->load($cursorSlot);
        $val = $context->builder->call(
            $context->lookupFunction('strtol'),
            $cur,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $outPtr, $val);
        $context->builder->store($endPtr, $cursorSlot);
    }

    private static function scanString(
        Context $context,
        Value $cursorSlot,
        Value $outPtr,
        $i8,
        $i8p,
        $sizeT,
        $i64
    ): void {
        $id = (string) \spl_object_id($context);
        $start = $context->builder->load($cursorSlot);
        $head = BasicBlockHelper::append($context, 'thin_scanf_s_head_'.$id);
        $body = BasicBlockHelper::append($context, 'thin_scanf_s_body_'.$id);
        $done = BasicBlockHelper::append($context, 'thin_scanf_s_done_'.$id);
        $cursorTmp = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($start, $cursorTmp);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $cur = $context->builder->load($cursorTmp);
        $ch = $context->builder->load($context->builder->pointerCast($cur, $i8->pointerType(0)));
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $isSpace = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x20, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x09, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0a, false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0x0d, false))
                )
            )
        );
        $stop = $context->builder->or($isNul, $isSpace);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $context->builder->store(
            $context->builder->gep($cur, $sizeT->constInt(1, false)),
            $cursorTmp
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $end = $context->builder->load($cursorTmp);
        $len = $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($start, $i64)
        );
        $newStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $start
        );
        $context->refcount->disableRefcount($newStr);
        $context->builder->call($context->lookupFunction('__value__writeString'), $outPtr, $newStr);
        $context->builder->store($end, $cursorSlot);
    }

    private static function ensureWriters(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        foreach ([
            ['__value__writeNull', $void, [$valuePtr]],
            ['__value__writeLong', $void, [$valuePtr, $i64]],
            ['__value__writeString', $void, [$valuePtr, $strPtr]],
            ['__value__writeHashtable', $void, [$valuePtr, $context->getTypeFromString('__hashtable__*')]],
            ['__string__init', $strPtr, [$i64, $i8p]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }
}
