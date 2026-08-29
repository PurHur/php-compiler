<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;

/**
 * LLVM lookup bridges for recorded internal functions under thin AOT reflection (#28780).
 */
final class ReflectionInternalFunctionLookupRuntime
{
    public static function implement(Context $context, string $metadataJson): void
    {
        $metadata = self::decodeMetadata($metadataJson);
        if ([] === $metadata) {
            return;
        }

        LibcExtern::ensureStrcmpDecl($context);
        self::implementFuncParamCount($context, $metadata);
        self::implementParamNameAt($context, $metadata);
        self::implementParamTypeLabelAt($context, $metadata);
        self::implementReturnTypeLabel($context, $metadata);
        self::implementParamDefaultAvailable($context, $metadata);
    }

    /**
     * @param array<string, array{params: array<int, array{name: string, type: ?string, hasDefault: bool}>, return: ?string}> $metadata
     */
    private static function implementFuncParamCount(Context $context, array $metadata): void
    {
        $arity = [];
        foreach (['strlen', 'array_map', 'count'] as $builtin) {
            $names = BuiltinParamNames::paramNamesForInternalFunction($builtin);
            if (null !== $names) {
                $arity[strtolower($builtin)] = \count($names);
            }
        }
        foreach ($metadata as $funcLc => $entry) {
            $arity[$funcLc] = \count($entry['params']);
        }
        ReflectionFunctionParamCountLookupRuntime::implementParamCountBridge($context, $arity);
    }

    /**
     * @param array<string, array{params: array<int, array{name: string, type: ?string, hasDefault: bool}>, return: ?string}> $metadata
     */
    private static function implementParamNameAt(Context $context, array $metadata): void
    {
        self::implementIndexedStringLookup(
            $context,
            '__compiler_refl_func_param_name_at',
            $metadata,
            static fn (array $param): ?string => $param['name'] ?? null
        );
    }

    /**
     * @param array<string, array{params: array<int, array{name: string, type: ?string, hasDefault: bool}>, return: ?string}> $metadata
     */
    private static function implementParamTypeLabelAt(Context $context, array $metadata): void
    {
        self::implementIndexedStringLookup(
            $context,
            '__compiler_refl_func_param_type_label_at',
            $metadata,
            static fn (array $param): ?string => $param['type'] ?? null
        );
    }

    /**
     * @param array<string, array{params: array<int, array{name: string, type: ?string, hasDefault: bool}>, return: ?string}> $metadata
     */
    private static function implementReturnTypeLabel(Context $context, array $metadata): void
    {
        $abiName = '__compiler_refl_func_return_type_label';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i8p, false, $i8p);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_func_return_type_entry');
        $context->builder->positionAtEnd($entry);
        $funcCstr = $fn->getParam(0);
        $nullRet = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $merge = BasicBlockHelper::append($context, 'refl_func_return_type_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($nullRet, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($metadata as $funcLc => $entryData) {
            $label = $entryData['return'] ?? null;
            if (null === $label || '' === $label) {
                continue;
            }
            $check = BasicBlockHelper::append($context, 'refl_func_return_type_check_'.$seq);
            $match = BasicBlockHelper::append($context, 'refl_func_return_type_match_'.$seq);
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
            $fallthrough = BasicBlockHelper::append($context, 'refl_func_return_type_next_'.$seq);
            $context->builder->branchIf($nameOk, $match, $fallthrough);
            $context->builder->positionAtEnd($match);
            $labelCstr = $context->builder->pointerCast($context->constantFromString($label), $i8p);
            $context->builder->store($labelCstr, $resultSlot);
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

    /**
     * @param array<string, array{params: array<int, array{name: string, type: ?string, hasDefault: bool}>, return: ?string}> $metadata
     */
    private static function implementParamDefaultAvailable(Context $context, array $metadata): void
    {
        $abiName = '__compiler_refl_func_param_default_available';
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
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('refl_func_param_default_entry');
        $context->builder->positionAtEnd($entry);
        $funcCstr = $fn->getParam(0);
        $index = $fn->getParam(1);
        $falseVal = $i1->constInt(0, false);
        $trueVal = $i1->constInt(1, false);
        $merge = BasicBlockHelper::append($context, 'refl_func_param_default_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($falseVal, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($metadata as $funcLc => $entryData) {
            foreach ($entryData['params'] as $paramIndex => $param) {
                if (empty($param['hasDefault'])) {
                    continue;
                }
                $funcCheck = BasicBlockHelper::append($context, 'refl_func_param_default_fcheck_'.$seq);
                $indexCheck = BasicBlockHelper::append($context, 'refl_func_param_default_icheck_'.$seq);
                $match = BasicBlockHelper::append($context, 'refl_func_param_default_match_'.$seq);
                $context->builder->positionAtEnd($next);
                $context->builder->branch($funcCheck);
                $context->builder->positionAtEnd($funcCheck);
                $expected = $context->constantFromString(strtolower((string) $funcLc));
                $nameEq = $context->builder->call(
                    $context->lookupFunction('strcmp'),
                    $funcCstr,
                    $context->builder->pointerCast($expected, $i8p)
                );
                $nameOk = $context->builder->icmp(Builder::INT_EQ, $nameEq, $i32->constInt(0, false));
                $fallthrough = BasicBlockHelper::append($context, 'refl_func_param_default_next_'.$seq);
                $context->builder->branchIf($nameOk, $indexCheck, $fallthrough);
                $context->builder->positionAtEnd($indexCheck);
                $indexOk = $context->builder->icmp(
                    Builder::INT_EQ,
                    $index,
                    $i64->constInt((int) $paramIndex, false)
                );
                $context->builder->branchIf($indexOk, $match, $fallthrough);
                $context->builder->positionAtEnd($match);
                $context->builder->store($trueVal, $resultSlot);
                $context->builder->branch($merge);
                $next = $fallthrough;
                ++$seq;
            }
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /**
     * @param array<string, array{params: array<int, array{name: string, type: ?string, hasDefault: bool}>, return: ?string}> $metadata
     * @param callable(array): ?string $pick
     */
    private static function implementIndexedStringLookup(
        Context $context,
        string $abiName,
        array $metadata,
        callable $pick,
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i8p, false, $i8p, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock($abiName.'_entry');
        $context->builder->positionAtEnd($entry);
        $funcCstr = $fn->getParam(0);
        $index = $fn->getParam(1);
        $nullRet = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $merge = BasicBlockHelper::append($context, $abiName.'_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($nullRet, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($metadata as $funcLc => $entryData) {
            foreach ($entryData['params'] as $paramIndex => $param) {
                $value = $pick($param);
                if (null === $value || '' === $value) {
                    continue;
                }
                $funcCheck = BasicBlockHelper::append($context, $abiName.'_fcheck_'.$seq);
                $indexCheck = BasicBlockHelper::append($context, $abiName.'_icheck_'.$seq);
                $match = BasicBlockHelper::append($context, $abiName.'_match_'.$seq);
                $context->builder->positionAtEnd($next);
                $context->builder->branch($funcCheck);
                $context->builder->positionAtEnd($funcCheck);
                $expected = $context->constantFromString(strtolower((string) $funcLc));
                $nameEq = $context->builder->call(
                    $context->lookupFunction('strcmp'),
                    $funcCstr,
                    $context->builder->pointerCast($expected, $i8p)
                );
                $nameOk = $context->builder->icmp(Builder::INT_EQ, $nameEq, $i32->constInt(0, false));
                $fallthrough = BasicBlockHelper::append($context, $abiName.'_next_'.$seq);
                $context->builder->branchIf($nameOk, $indexCheck, $fallthrough);
                $context->builder->positionAtEnd($indexCheck);
                $indexOk = $context->builder->icmp(
                    Builder::INT_EQ,
                    $index,
                    $i64->constInt((int) $paramIndex, false)
                );
                $context->builder->branchIf($indexOk, $match, $fallthrough);
                $context->builder->positionAtEnd($match);
                $valueCstr = $context->builder->pointerCast($context->constantFromString($value), $i8p);
                $context->builder->store($valueCstr, $resultSlot);
                $context->builder->branch($merge);
                $next = $fallthrough;
                ++$seq;
            }
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $context->builder->returnValue($context->builder->load($resultSlot));
        $context->registerFunction($abiName, $fn);
    }

    /**
     * @return array<string, array{params: array<int, array{name: string, type: ?string, hasDefault: bool}>, return: ?string}>
     */
    private static function decodeMetadata(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $func => $entry) {
            if (!\is_string($func) || !\is_array($entry)) {
                continue;
            }
            $params = [];
            foreach ($entry['params'] ?? [] as $idx => $param) {
                if (!\is_array($param)) {
                    continue;
                }
                $params[(int) $idx] = [
                    'name' => (string) ($param['name'] ?? ''),
                    'type' => isset($param['type']) ? (string) $param['type'] : null,
                    'hasDefault' => !empty($param['hasDefault']),
                ];
            }
            $out[strtolower($func)] = [
                'params' => $params,
                'return' => isset($entry['return']) ? (string) $entry['return'] : null,
            ];
        }

        return $out;
    }
}
