<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;

/** JIT/AOT link for __compiler_refl_*_named_* embedded tables (#17658). */
final class ReflectionNamedArgumentsLookupRuntime
{
    public static function implement(Context $context, string $functionParamsJson, string $methodParamsJson): void
    {
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
        self::implementFunctionCountBridge($context, self::decodeFunctionParams($functionParamsJson));
        self::implementFunctionNameAtBridge($context, self::decodeFunctionParams($functionParamsJson));
        self::implementMethodCountBridge($context, self::decodeMethodParams($methodParamsJson));
        self::implementMethodNameAtBridge($context, self::decodeMethodParams($methodParamsJson));
        $context->builder->clearInsertionPosition();
    }

    /** @param array<string, list<string>> $functionParams */
    private static function implementFunctionCountBridge(Context $context, array $functionParams): void
    {
        $abiName = '__compiler_refl_func_named_count';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $i8p);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_func_named_count_entry');
        $context->builder->positionAtEnd($entry);
        $funcCstr = $fn->getParam(0);
        $zero = $sizeT->constInt(0, false);

        if ([] === $functionParams) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_func_named_count_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($functionParams as $funcLc => $names) {
            $check = BasicBlockHelper::append($context, 'refl_func_named_count_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_func_named_count_match_'.$seq);
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
            $context->builder->store($sizeT->constInt(\count($names), false), $resultSlot);
            $context->builder->branch($merge);

            $next = $merge;
            $merge = BasicBlockHelper::append($context, 'refl_func_named_count_merge_'.$seq);
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, list<string>> $functionParams */
    private static function implementFunctionNameAtBridge(Context $context, array $functionParams): void
    {
        $abiName = '__compiler_refl_func_named_at';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i8p, false, $i8p, $sizeT);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_func_named_at_entry');
        $context->builder->positionAtEnd($entry);
        $funcCstr = $fn->getParam(0);
        $idx = $fn->getParam(1);
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);

        if ([] === $functionParams) {
            $context->builder->returnValue($empty);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_func_named_at_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($empty, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($functionParams as $funcLc => $names) {
            $funcCheck = BasicBlockHelper::append($context, 'refl_func_named_at_fcheck_'.$seq);
            $funcMatch = BasicBlockHelper::append($context, 'refl_func_named_at_fmatch_'.$seq);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($funcCheck);
            $context->builder->positionAtEnd($funcCheck);

            $expected = $context->constantFromString(strtolower($funcLc));
            $nameEq = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $funcCstr,
                $context->builder->pointerCast($expected, $i8p)
            );
            $nameOk = $context->builder->icmp(Builder::INT_EQ, $nameEq, $i32->constInt(0, false));
            $context->builder->branchIf($nameOk, $funcMatch, $merge);

            $context->builder->positionAtEnd($funcMatch);
            $idxNext = $funcMatch;
            foreach ($names as $pos => $paramName) {
                $idxCheck = BasicBlockHelper::append($context, 'refl_func_named_at_icheck_'.$seq.'_'.$pos);
                $idxMatch = BasicBlockHelper::append($context, 'refl_func_named_at_imatch_'.$seq.'_'.$pos);
                $context->builder->positionAtEnd($idxNext);
                $context->builder->branch($idxCheck);
                $context->builder->positionAtEnd($idxCheck);
                $idxOk = $context->builder->icmp(Builder::INT_EQ, $idx, $sizeT->constInt($pos, false));
                $idxMiss = ($pos < \count($names) - 1)
                    ? BasicBlockHelper::append($context, 'refl_func_named_at_imiss_'.$seq.'_'.$pos)
                    : $merge;
                $context->builder->branchIf($idxOk, $idxMatch, $idxMiss);
                $context->builder->positionAtEnd($idxMatch);
                $context->builder->store(
                    $context->builder->pointerCast($context->constantFromString($paramName), $i8p),
                    $resultSlot
                );
                $context->builder->branch($merge);
                $idxNext = $idxMiss;
            }

            $next = $merge;
            $merge = BasicBlockHelper::append($context, 'refl_func_named_at_merge_'.$seq);
            ++$seq;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, array<string, list<string>>> $methodParams */
    private static function implementMethodCountBridge(Context $context, array $methodParams): void
    {
        $abiName = '__compiler_refl_method_named_count';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $i8p, $i8p);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_method_named_count_entry');
        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $zero = $sizeT->constInt(0, false);

        if ([] === $methodParams) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_method_named_count_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($methodParams as $classLc => $methods) {
            foreach ($methods as $methodLc => $names) {
                $check = BasicBlockHelper::append($context, 'refl_method_named_count_check_'.$seq);
                $match = BasicBlockHelper::append($context, 'refl_method_named_count_match_'.$seq);
                $context->builder->positionAtEnd($next);
                $context->builder->branch($check);
                $context->builder->positionAtEnd($check);

                $classExpected = $context->constantFromString(strtolower($classLc));
                $classEq = $context->builder->call(
                    $context->lookupFunction('strcmp'),
                    $classCstr,
                    $context->builder->pointerCast($classExpected, $i8p)
                );
                $classOk = $context->builder->icmp(Builder::INT_EQ, $classEq, $i32->constInt(0, false));

                $methodExpected = $context->constantFromString(strtolower($methodLc));
                $methodEq = $context->builder->call(
                    $context->lookupFunction('strcmp'),
                    $methodCstr,
                    $context->builder->pointerCast($methodExpected, $i8p)
                );
                $methodOk = $context->builder->icmp(Builder::INT_EQ, $methodEq, $i32->constInt(0, false));
                $both = $context->builder->and($classOk, $methodOk);
                $context->builder->branchIf($both, $match, $merge);

                $context->builder->positionAtEnd($match);
                $context->builder->store($sizeT->constInt(\count($names), false), $resultSlot);
                $context->builder->branch($merge);

                $next = $merge;
                $merge = BasicBlockHelper::append($context, 'refl_method_named_count_merge_'.$seq);
                ++$seq;
            }
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, array<string, list<string>>> $methodParams */
    private static function implementMethodNameAtBridge(Context $context, array $methodParams): void
    {
        $abiName = '__compiler_refl_method_named_at';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i8p, false, $i8p, $i8p, $sizeT);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_method_named_at_entry');
        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $idx = $fn->getParam(2);
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);

        if ([] === $methodParams) {
            $context->builder->returnValue($empty);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'refl_method_named_at_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($empty, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($methodParams as $classLc => $methods) {
            foreach ($methods as $methodLc => $names) {
                $methCheck = BasicBlockHelper::append($context, 'refl_method_named_at_mcheck_'.$seq);
                $methMatch = BasicBlockHelper::append($context, 'refl_method_named_at_mmatch_'.$seq);
                $context->builder->positionAtEnd($next);
                $context->builder->branch($methCheck);
                $context->builder->positionAtEnd($methCheck);

                $classExpected = $context->constantFromString(strtolower($classLc));
                $classEq = $context->builder->call(
                    $context->lookupFunction('strcmp'),
                    $classCstr,
                    $context->builder->pointerCast($classExpected, $i8p)
                );
                $classOk = $context->builder->icmp(Builder::INT_EQ, $classEq, $i32->constInt(0, false));
                $methodExpected = $context->constantFromString(strtolower($methodLc));
                $methodEq = $context->builder->call(
                    $context->lookupFunction('strcmp'),
                    $methodCstr,
                    $context->builder->pointerCast($methodExpected, $i8p)
                );
                $methodOk = $context->builder->icmp(Builder::INT_EQ, $methodEq, $i32->constInt(0, false));
                $both = $context->builder->and($classOk, $methodOk);
                $context->builder->branchIf($both, $methMatch, $merge);

                $context->builder->positionAtEnd($methMatch);
                $idxNext = $methMatch;
                foreach ($names as $pos => $paramName) {
                    $idxCheck = BasicBlockHelper::append($context, 'refl_method_named_at_icheck_'.$seq.'_'.$pos);
                    $idxMatch = BasicBlockHelper::append($context, 'refl_method_named_at_imatch_'.$seq.'_'.$pos);
                    $context->builder->positionAtEnd($idxNext);
                    $context->builder->branch($idxCheck);
                    $context->builder->positionAtEnd($idxCheck);
                    $idxOk = $context->builder->icmp(Builder::INT_EQ, $idx, $sizeT->constInt($pos, false));
                    $idxMiss = ($pos < \count($names) - 1)
                        ? BasicBlockHelper::append($context, 'refl_method_named_at_imiss_'.$seq.'_'.$pos)
                        : $merge;
                    $context->builder->branchIf($idxOk, $idxMatch, $idxMiss);
                    $context->builder->positionAtEnd($idxMatch);
                    $context->builder->store(
                        $context->builder->pointerCast($context->constantFromString($paramName), $i8p),
                        $resultSlot
                    );
                    $context->builder->branch($merge);
                    $idxNext = $idxMiss;
                }

                $next = $merge;
                $merge = BasicBlockHelper::append($context, 'refl_method_named_at_merge_'.$seq);
                ++$seq;
            }
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @return array<string, list<string>> */
    private static function decodeFunctionParams(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, array<string, list<string>>> */
    private static function decodeMethodParams(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
