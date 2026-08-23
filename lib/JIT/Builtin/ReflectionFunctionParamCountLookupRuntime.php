<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_refl_func_param_count / is_user_defined (#34218).
 */
final class ReflectionFunctionParamCountLookupRuntime
{
    public static function implement(
        Context $context,
        string $userArityJson,
        string $internalArityJson,
        string $userNamesJson
    ): void {
        LibcExtern::ensureStrcmpDecl($context);
        $userArity = self::decodeArityMap($userArityJson);
        $internalArity = self::decodeArityMap($internalArityJson);
        $merged = $internalArity + $userArity; // user wins on key clash
        self::implementParamCountBridge($context, $merged);
        self::implementIsUserDefinedBridge($context, self::decodeNameList($userNamesJson));
        $context->builder->clearInsertionPosition();
    }

    /** @param array<string, int> $arityByFunc */
    private static function implementParamCountBridge(Context $context, array $arityByFunc): void
    {
        $abiName = '__compiler_refl_func_param_count';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $i8p);
        // Reuse declaration from ReflectionNative::registerDeclarations — addFunction
        // on an existing name silently renames to *.1 with no callers (#31894 / #34218).
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_func_param_count_entry');
        $context->builder->positionAtEnd($entry);
        $funcCstr = $fn->getParam(0);
        $zero = $sizeT->constInt(0, false);

        if ([] === $arityByFunc) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_func_param_count_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($arityByFunc as $funcLc => $arity) {
            $check = BasicBlockHelper::append($context, 'refl_func_param_count_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_func_param_count_match_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);

            $expected = $context->constantFromString(strtolower((string) $funcLc));
            $nameEq = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $funcCstr,
                $context->builder->pointerCast($expected, $i8p)
            );
            $nameOk = $context->builder->icmp(Builder::INT_EQ, $nameEq, $i32->constInt(0, false));
            // On miss continue to next check; last miss falls to merge with 0.
            $fallthrough = BasicBlockHelper::append($context, 'refl_func_param_count_next_'.$seq);
            $context->builder->branchIf($nameOk, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($sizeT->constInt((int) $arity, false), $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param list<string> $userNames */
    private static function implementIsUserDefinedBridge(Context $context, array $userNames): void
    {
        $abiName = '__compiler_refl_func_is_user_defined';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i1, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_func_is_user_defined_entry');
        $context->builder->positionAtEnd($entry);
        $funcCstr = $fn->getParam(0);
        $falseVal = $i1->constInt(0, false);
        $trueVal = $i1->constInt(1, false);

        if ([] === $userNames) {
            $context->builder->returnValue($falseVal);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_func_is_user_defined_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($falseVal, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($userNames as $funcLc) {
            $check = BasicBlockHelper::append($context, 'refl_func_is_user_defined_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_func_is_user_defined_match_'.$seq);
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
            $fallthrough = BasicBlockHelper::append($context, 'refl_func_is_user_defined_next_'.$seq);
            $context->builder->branchIf($nameOk, $match, $fallthrough);

            $context->builder->positionAtEnd($match);
            $context->builder->store($trueVal, $resultSlot);
            $context->builder->branch($merge);

            $next = $fallthrough;
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @return array<string, int> */
    private static function decodeArityMap(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name => $arity) {
            if (\is_string($name) && '' !== $name && is_numeric($arity)) {
                $out[strtolower($name)] = (int) $arity;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function decodeNameList(string $json): array
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
