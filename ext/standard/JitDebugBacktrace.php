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
        $block = $context->jitEnclosingBlock;
        $file = $block instanceof Block
            ? ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE)
            : '';

        $frames = [
            self::frameVariable($context, 'debug_backtrace', $file),
        ];
        if ($block instanceof Block && null !== $block->func) {
            $function = $block->func->getScopedName();
            if ('' === $function) {
                $function = $block->func->name ?? '';
            }
            $enclosingFile = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
            $frames[] = self::frameVariable($context, $function, $enclosingFile);
        }

        $packed = HashTableHelper::packVariables($context, $frames);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($packed)
        );

        return $ptr;
    }

    private static function frameVariable(Context $context, string $fn, string $path): JITVariable
    {
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

        return new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $entry
        );
    }
}
