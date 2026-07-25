<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Builtin\SensitiveParamRuntime;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\SensitiveParamHelper;
use PHPCompiler\JIT\VarFetchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCfg\Func;
use PHPLLVM\Value;

/** LLVM lowering for debug_backtrace() (#1378, #1056, #3626, #4621). */
final class JitDebugBacktrace
{
    /** @return Value */
    public static function invoke(Context $context, ?JITVariable $optionsArg = null): Value
    {
        return self::wrapHashTable($context, self::emitTrace($context, $optionsArg));
    }

    private static function emitTrace(Context $context, ?JITVariable $optionsArg): Value
    {
        $ht = HashTableHelper::alloc($context);
        $index = 0;

        $block = $context->jitEnclosingBlock;
        if ($block instanceof Block && null !== $block->func) {
            $function = $block->func->name ?? '';
            // php-cfg `{anonymous}#N` → Zend `{closure}` (#23184); empty top-level stays unset/{main}.
            if (preg_match('/^\{anonymous\}#\d+$/', $function)) {
                $function = '{closure}';
            } elseif ('' === $function && null === $block->func->class) {
                $function = '{closure}';
            }
            if ('' !== $function) {
                self::appendUserFrame(
                    $context,
                    $ht,
                    $index++,
                    $block,
                    ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE),
                    $function,
                    $optionsArg
                );
            }
        }

        self::appendStringFrame($context, $ht, $index, '', '{main}');

        return $ht;
    }

    private static function appendUserFrame(
        Context $context,
        Value $traceHt,
        int $index,
        Block $block,
        string $file,
        string $function,
        ?JITVariable $optionsArg
    ): void {
        $frame = HashTableHelper::alloc($context);
        self::appendStringFrameToAssoc(
            $context,
            $frame,
            $file,
            $function,
            self::frameClassName($block),
            self::frameCallType($block)
        );

        if ([] !== $block->paramNames) {
            $ignoreArgs = SensitiveParamHelper::ignoreArgsBit($context, $optionsArg);
            $withArgs = BasicBlockHelper::append($context, 'dbg_bt_args');
            $done = BasicBlockHelper::append($context, 'dbg_bt_frame_done');
            $context->builder->branchIf($ignoreArgs, $done, $withArgs);

            $context->builder->positionAtEnd($withArgs);
            $argsHt = SensitiveParamHelper::buildArgsArray($context, $block);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                $frame,
                $context->builder->load($context->constantStringFromString('args')),
                $argsHt
            );
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
        }

        self::maybeAppendObjectFrame($context, $frame, $block, $optionsArg);

        HashTableHelper::setAtIndex(
            $context,
            $traceHt,
            $context->constantFromInteger($index, 'size_t'),
            new JITVariable(
                $context,
                JITVariable::TYPE_HASHTABLE,
                JITVariable::KIND_VALUE,
                $frame
            )
        );
    }

    private static function maybeAppendObjectFrame(
        Context $context,
        Value $frameHt,
        Block $block,
        ?JITVariable $optionsArg,
    ): void {
        if (!self::isInstanceMethodBlock($block)) {
            return;
        }
        $thisVar = VarFetchHelper::bindingByName($context, $block, 'this');
        if (null === $thisVar || JITVariable::TYPE_OBJECT !== $thisVar->type) {
            return;
        }

        $provideObject = SensitiveParamRuntime::provideObjectBit($context, $optionsArg);
        $withObject = BasicBlockHelper::append($context, 'dbg_bt_object');
        $done = BasicBlockHelper::append($context, 'dbg_bt_object_done');
        $context->builder->branchIf($provideObject, $withObject, $done);

        $context->builder->positionAtEnd($withObject);
        HashTableHelper::setAtStringKey(
            $context,
            $frameHt,
            $context->builder->load($context->constantStringFromString('object')),
            $thisVar
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function isInstanceMethodBlock(Block $block): bool
    {
        if (null === $block->func || null === $block->func->class) {
            return false;
        }

        return 0 === (($block->func->flags ?? 0) & Func::FLAG_STATIC);
    }

    private static function appendStringFrame(
        Context $context,
        Value $traceHt,
        int $index,
        string $file,
        string $function
    ): void {
        $frame = HashTableHelper::alloc($context);
        self::appendStringFrameToAssoc($context, $frame, $file, $function);
        HashTableHelper::setAtIndex(
            $context,
            $traceHt,
            $context->constantFromInteger($index, 'size_t'),
            new JITVariable(
                $context,
                JITVariable::TYPE_HASHTABLE,
                JITVariable::KIND_VALUE,
                $frame
            )
        );
    }

    private static function appendStringFrameToAssoc(
        Context $context,
        Value $frameHt,
        string $file,
        string $function,
        string $class = '',
        string $callType = ''
    ): void {
        $fileJit = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($file))
        );
        $fnJit = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($function))
        );
        $lineJit = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->constantFromInteger(0, 'int64')
        );
        HashTableHelper::setAtStringKey(
            $context,
            $frameHt,
            $context->builder->load($context->constantStringFromString('file')),
            $fileJit
        );
        HashTableHelper::setAtStringKey(
            $context,
            $frameHt,
            $context->builder->load($context->constantStringFromString('line')),
            $lineJit
        );
        if ('' !== $class) {
            $classJit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($class))
            );
            HashTableHelper::setAtStringKey(
                $context,
                $frameHt,
                $context->builder->load($context->constantStringFromString('class')),
                $classJit
            );
            $typeJit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString('' !== $callType ? $callType : '->'))
            );
            HashTableHelper::setAtStringKey(
                $context,
                $frameHt,
                $context->builder->load($context->constantStringFromString('type')),
                $typeJit
            );
        }
        HashTableHelper::setAtStringKey(
            $context,
            $frameHt,
            $context->builder->load($context->constantStringFromString('function')),
            $fnJit
        );
    }

    private static function frameClassName(Block $block): string
    {
        if (null === $block->func || null === $block->func->class) {
            return '';
        }

        return $block->func->class->value ?? $block->func->class->name ?? '';
    }

    private static function frameCallType(Block $block): string
    {
        if (null === $block->func || null === $block->func->class) {
            return '';
        }
        if (($block->func->flags ?? 0) & Func::FLAG_STATIC) {
            return '::';
        }

        return '->';
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
