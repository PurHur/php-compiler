<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Value;

/** LLVM lowering for debug_backtrace() (#1378). */
final class JitDebugBacktrace
{
    /** @return Value */
    public static function invoke(Context $context): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $block = $context->jitEnclosingBlock;
        $file = $block instanceof Block
            ? ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE)
            : '';

        self::appendFrame($context, $ht, $zero, 'debug_backtrace', $file);
        if ($block instanceof Block && null !== $block->func) {
            $function = $block->func->getScopedName();
            if ('' === $function) {
                $function = $block->func->name ?? '';
            }
            $enclosingFile = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
            self::appendFrame($context, $ht, $one, $function, $enclosingFile);
        }

        $packed = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $ht
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($packed)
        );

        return $ptr;
    }

    private static function appendFrame(
        Context $context,
        Value $arr,
        Value $idx,
        string $fn,
        string $path
    ): void {
        $entry = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $entry,
            $context->builder->load($context->constantStringFromString('file')),
            $context->builder->load($context->constantStringFromString($path))
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $entry,
            $context->builder->load($context->constantStringFromString('line')),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $entry,
            $context->builder->load($context->constantStringFromString('function')),
            $context->builder->load($context->constantStringFromString($fn))
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $arr,
            $idx,
            $entry
        );
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($arr, $map['numElements']));
        $next = $context->builder->add($idx, $one);
        $context->builder->store(
            $context->builder->select(
                $context->builder->icmp(\PHPLLVM\Builder::INT_UGT, $next, $count),
                $next,
                $count
            ),
            $context->builder->structGep($arr, $map['numElements'])
        );
    }
}
