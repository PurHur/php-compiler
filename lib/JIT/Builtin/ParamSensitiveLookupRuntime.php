<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT link for __compiler_param_*_is_sensitive via embedded tables (#16130). */
final class ParamSensitiveLookupRuntime
{
    public static function implement(Context $context, string $functionParamsJson, string $methodParamsJson): void
    {
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
        self::implementFunctionBridge($context, self::decodeFunctionParams($functionParamsJson));
        self::implementMethodBridge($context, self::decodeMethodParams($methodParamsJson));
    }

    /** @param array<string, list<int>> $functionParams */
    private static function implementFunctionBridge(Context $context, array $functionParams): void
    {
        $abiName = '__compiler_param_func_is_sensitive';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i1, false, $i8p, $i64);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('param_func_is_sensitive_entry');
        $context->builder->positionAtEnd($entry);

        $funcCstr = $fn->getParam(0);
        $idx = $fn->getParam(1);
        $falseVal = $i1->constInt(0, false);
        $trueVal = $i1->constInt(1, false);

        if ([] === $functionParams) {
            $context->builder->returnValue($falseVal);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'param_func_is_sensitive_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($falseVal, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($functionParams as $funcLc => $indices) {
            foreach ($indices as $paramIndex) {
                $check = BasicBlockHelper::append($context, 'param_func_is_sensitive_check_'.$seq);
                $match = BasicBlockHelper::append($context, 'param_func_is_sensitive_match_'.$seq);
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
                $idxOk = $context->builder->icmp(Builder::INT_EQ, $idx, $i64->constInt((int) $paramIndex, false));
                $both = $context->builder->and($nameOk, $idxOk);
                $context->builder->branchIf($both, $match, $merge);

                $context->builder->positionAtEnd($match);
                $context->builder->store($trueVal, $resultSlot);
                $context->builder->branch($merge);

                $next = $merge;
                $merge = BasicBlockHelper::append($context, 'param_func_is_sensitive_merge_'.$seq);
                ++$seq;
            }
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @param array<string, array<string, list<int>>> $methodParams */
    private static function implementMethodBridge(Context $context, array $methodParams): void
    {
        $abiName = '__compiler_param_method_is_sensitive';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i1, false, $i8p, $i8p, $i64);
        $fn = $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('param_method_is_sensitive_entry');
        $context->builder->positionAtEnd($entry);

        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $pos = $fn->getParam(2);
        $falseVal = $i1->constInt(0, false);
        $trueVal = $i1->constInt(1, false);

        if ([] === $methodParams) {
            $context->builder->returnValue($falseVal);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, 'param_method_is_sensitive_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($falseVal, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($methodParams as $classLc => $methods) {
            foreach ($methods as $methodLc => $positions) {
                foreach ($positions as $position) {
                    $check = BasicBlockHelper::append($context, 'param_method_is_sensitive_check_'.$seq);
                    $match = BasicBlockHelper::append($context, 'param_method_is_sensitive_match_'.$seq);
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

                    $posOk = $context->builder->icmp(Builder::INT_EQ, $pos, $i64->constInt((int) $position, false));
                    $all = $context->builder->and($classOk, $methodOk);
                    $all = $context->builder->and($all, $posOk);
                    $context->builder->branchIf($all, $match, $merge);

                    $context->builder->positionAtEnd($match);
                    $context->builder->store($trueVal, $resultSlot);
                    $context->builder->branch($merge);

                    $next = $merge;
                    $merge = BasicBlockHelper::append($context, 'param_method_is_sensitive_merge_'.$seq);
                    ++$seq;
                }
            }
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /** @return array<string, list<int>> */
    private static function decodeFunctionParams(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, array<string, list<int>>> */
    private static function decodeMethodParams(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
