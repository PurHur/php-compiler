<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for tempnam() via TempnamJitHelper PHP (#15685, #29940).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTempnam;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitTempnam
{
    private static int $blockSerial = 0;

    /** @return Value */
    public static function lowerDirectory(Context $context, JITVariable $arg): Value
    {
        // Z_PARAM_PATH: soft-null DEP+'' outside strict_types (#21595, reverts #20960);
        // empty directory → system temp in TempnamJitHelper / FsDirJitHelper.
        return JitStringBuiltinArg::lowerPath($context, $arg, 'tempnam', 0, 'directory');
    }

    /**
     * @return Value `__value__*` — string path or false (never a dangling stack box from ABI)
     */
    public static function invoke(Context $context, Value $dirStr, Value $prefixStr): Value
    {
        return self::materializeStringOrFalse(
            $context,
            StringTempnam::invoke($context, $dirStr, $prefixStr)
        );
    }

    /**
     * Box nullable `__string__*` into string|false — peer {@see JitFileGetContents} / {@see JitGetcwd}.
     *
     * Must run in the *caller* frame: {@see JitValueBox::alloc} is entryAlloca; returning that
     * pointer from `__phpc_jit_tempnam` left a dangling stack slot (#29940 AOT NULL).
     */
    public static function materializeStringOrFalse(Context $context, Value $pathStr): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $pathStr, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'tempnam_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'tempnam_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'tempnam_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $pathStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
