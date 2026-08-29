<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Module helper: map internal-function type label cstr → boxed ReflectionType (#28780).
 *
 * Keeps strcmp dispatch out of the main script function (LLVM verify / sealed-BB free).
 */
final class ReflectionTypeFromLabelLookupRuntime
{
    /** @param list<string> $labels */
    public static function implement(Context $context, array $labels): void
    {
        $abiName = '__compiler_refl_type_from_label_cstr';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valuePtrTy = $context->getTypeFromString('__value__*');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($valuePtrTy, false, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use (
            $context,
            $fn,
            $labels,
            $i8p
        ): void {
            $entry = $fn->appendBasicBlock('refl_type_from_label_entry');
            $context->builder->positionAtEnd($entry);
            $labelCstr = $fn->getParam(0);

            if ([] === $labels) {
                $context->builder->returnValue(
                    ReflectionTypeJitHelper::emitTypeFromLabelHeap($context, '')
                );

                return;
            }

            LibcExtern::ensureStrcmpDecl($context);
            $i32 = $context->getTypeFromString('int32');
            $next = $entry;
            $seq = 0;

            foreach ($labels as $label) {
                $check = BasicBlockHelper::append($context, 'refl_type_from_label_check_'.$seq);
                $match = BasicBlockHelper::append($context, 'refl_type_from_label_match_'.$seq);
                $context->builder->positionAtEnd($next);
                $context->builder->branch($check);
                $context->builder->positionAtEnd($check);
                $expected = $context->builder->pointerCast($context->constantFromString($label), $i8p);
                $eq = $context->builder->call(
                    $context->lookupFunction('strcmp'),
                    $labelCstr,
                    $expected
                );
                $ok = $context->builder->icmp(Builder::INT_EQ, $eq, $i32->constInt(0, false));
                $fallthrough = BasicBlockHelper::append($context, 'refl_type_from_label_next_'.$seq);
                $context->builder->branchIf($ok, $match, $fallthrough);
                $context->builder->positionAtEnd($match);
                $context->builder->returnValue(
                    ReflectionTypeJitHelper::emitTypeFromLabelHeap($context, $label)
                );
                $next = $fallthrough;
                ++$seq;
            }

            $context->builder->positionAtEnd($next);
            $context->builder->returnValue(
                ReflectionTypeJitHelper::emitTypeFromLabelHeap($context, '')
            );
        });

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }
}
