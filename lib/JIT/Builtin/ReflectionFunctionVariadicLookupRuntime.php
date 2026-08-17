<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;

/** JIT/AOT link for __compiler_refl_func_is_variadic (#22045). */
final class ReflectionFunctionVariadicLookupRuntime
{
    public static function implement(Context $context, string $variadicJson): void
    {
        $abiName = '__compiler_refl_func_is_variadic';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $names = self::decodeNames($variadicJson);
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i1, false, $i8p);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_func_is_variadic_entry');
        $context->builder->positionAtEnd($entry);

        $funcCstr = $fn->getParam(0);
        $falseVal = $i1->constInt(0, false);
        $trueVal = $i1->constInt(1, false);

        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);

        if ([] === $names) {
            $context->builder->returnValue($falseVal);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_func_is_variadic_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($falseVal, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($names as $funcLc) {
            $check = BasicBlockHelper::append($context, 'refl_func_is_variadic_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_func_is_variadic_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $expected = $context->constantFromString(strtolower($funcLc));
            $nameEq = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $funcCstr,
                $context->builder->pointerCast($expected, $i8p)
            );
            $nameOk = $context->builder->icmp(Builder::INT_EQ, $nameEq, $i32->constInt(0, false));
            $context->builder->branchIf($nameOk, $match, $merge);

            $context->builder->positionAtEnd($match);
            $context->builder->store($trueVal, $resultSlot);
            $context->builder->branch($merge);

            $next = $merge;
            $merge = BasicBlockHelper::append($context, 'refl_func_is_variadic_merge_'.$seq);
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @return list<string> */
    private static function decodeNames(string $json): array
    {
        if ('' === $json || '[]' === $json) {
            return [];
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name) {
            if (\is_string($name) && '' !== $name) {
                $out[] = strtolower($name);
            }
        }

        return $out;
    }
}
