<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\SensitiveParamHelper;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCfg\Func;
use PHPLLVM\Value;

/** LLVM lowering for debug_print_backtrace() (#3314). */
final class JitDebugPrintBacktrace
{
    public static function invoke(
        Context $context,
        ?JITVariable $optionsArg = null,
        ?JITVariable $limitArg = null,
    ): Value {
        unset($limitArg);
        $block = $context->jitEnclosingBlock;
        if ($block instanceof Block && null !== $block->func) {
            $function = $block->func->name ?? '';
            if ('' === $function && null === $block->func->class) {
                $function = '{main}';
            }
            if ('{main}' !== $function) {
                $file = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
                $class = '';
                $type = '';
                if (null !== $block->func->class) {
                    $class = $block->func->class->value ?? $block->func->class->name ?? '';
                    $type = 0 !== (($block->func->flags ?? 0) & Func::FLAG_STATIC) ? '::' : '->';
                }
                self::echoFlatLine(
                    $context,
                    0,
                    $file,
                    0,
                    $class,
                    $type,
                    $function,
                    SensitiveParamHelper::ignoreArgsBit($context, $optionsArg),
                    $block
                );
            }
        }

        return self::returnNull($context);
    }

    private static function echoFlatLine(
        Context $context,
        int $index,
        string $file,
        int $line,
        string $class,
        string $type,
        string $function,
        Value $ignoreArgs,
        Block $block,
    ): void {
        $prefix = '#'.$index;
        if ('' !== $file) {
            $prefix .= ' '.$file.'('.$line.'):';
        }
        $prefix .= ' ';
        if ('' !== $class) {
            $prefix .= $class.$type;
        }
        $prefix .= $function;

        $ignoreBb = BasicBlockHelper::append($context, 'dbg_print_bt_ignore_args');
        $argsBb = BasicBlockHelper::append($context, 'dbg_print_bt_with_args');
        $doneBb = BasicBlockHelper::append($context, 'dbg_print_bt_line_done');
        $context->builder->branchIf($ignoreArgs, $ignoreBb, $argsBb);

        $context->builder->positionAtEnd($ignoreBb);
        ValueEchoHelper::echoLiteral($context, $prefix.'()'."\n");
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($argsBb);
        ValueEchoHelper::echoLiteral($context, $prefix.'('.self::formatCompileTimeArgs($block).')'."\n");
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function formatCompileTimeArgs(Block $block): string
    {
        if ([] === $block->paramNames) {
            return '';
        }
        $parts = [];
        $sensitive = $block->paramSensitive;
        foreach ($block->paramNames as $paramIdx => $name) {
            unset($name);
            if (\PHPCompiler\VM\SensitiveParamSupport::compileTimeParamIsSensitive($sensitive, $paramIdx)) {
                $parts[] = \PHPCompiler\VM\SensitiveParamJitHelper::traceArgLabel();

                continue;
            }
            $parts[] = '...';
        }

        return implode(', ', $parts);
    }

    private static function returnNull(Context $context): Value
    {
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

        return $nullPtr;
    }
}
