<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\VmStringCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for strrpos()/strripos() (#14752, #27184).
 *
 * Search via {@see VmStringCompare::findROffset} (memcmp IR). Result boxed as
 * `__value__*` int|false — bare i64 0 collided with hit-at-0 under AOT.
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrpos), PHP_FUNCTION(strripos)
 */
final class StringStrrpos
{
    public const NOT_FOUND = -1;

    public static function ensureLinked(Context $context): void
    {
        StringStrpos::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset,
        bool $caseInsensitive = false
    ): Value {
        StringStrpos::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $off = $offset ?? $i64->constInt(0, false);
        $found = VmStringCompare::findROffset(
            $context,
            $haystack,
            $needle,
            $off,
            $caseInsensitive
        );

        return self::boxFoundI64($context, $found);
    }

    /**
     * @param int|false $pos
     */
    public static function boxIntOrFalse(Context $context, int|false $pos): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'strrpos_box');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $pos) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        } else {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($pos, true)
            );
        }

        return $ptr;
    }

    private static function boxFoundI64(Context $context, Value $found): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'strrpos_box_found');
        $i64 = $context->getTypeFromString('int64');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isMiss = $context->builder->icmp(
            Builder::INT_EQ,
            $found,
            $i64->constInt(self::NOT_FOUND, true)
        );
        $missBb = BasicBlockHelper::append($context, 'strrpos_found_miss');
        $hitBb = BasicBlockHelper::append($context, 'strrpos_found_hit');
        $doneBb = BasicBlockHelper::append($context, 'strrpos_found_done');
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        JitValueBox::writeLong($context, $slot, $found);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }
}
