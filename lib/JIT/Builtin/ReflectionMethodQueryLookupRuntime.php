<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for ReflectionMethod param-count queries (#34216).
 *
 * php-src: ext/reflection/php_reflection.c zim_ReflectionFunctionAbstract_getNumberOfParameters
 * / getNumberOfRequiredParameters.
 *
 * Uses exact-case {@see strcmp} on ReflectionMethod's stored class/name strings.
 */
final class ReflectionMethodQueryLookupRuntime
{
    public static function implement(Context $context, string $tableJson): void
    {
        LibcExtern::ensureStrcmpDecl($context);
        $table = self::decodeTable($tableJson);
        self::implementParamCountBridge($context, $table, false);
        self::implementParamCountBridge($context, $table, true);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param array<string, array<string, array{params: int, required: int}>> $table
     */
    private static function implementParamCountBridge(Context $context, array $table, bool $required): void
    {
        $abiName = $required
            ? '__compiler_refl_method_required_param_count'
            : '__compiler_refl_method_param_count';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($sizeT, false, $i8p, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        $tag = $required ? 'refl_method_req_param_count' : 'refl_method_param_count';
        $entry = $fn->appendBasicBlock($tag.'_entry');
        $context->builder->positionAtEnd($entry);
        $classCstr = $fn->getParam(0);
        $methodCstr = $fn->getParam(1);
        $zero = $sizeT->constInt(0, false);

        if ([] === $table) {
            $context->builder->returnValue($zero);
            $context->registerFunction($abiName, $fn);

            return;
        }

        $merge = BasicBlockHelper::append($context, $tag.'_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $resultSlot);
        $next = $entry;
        $seq = 0;

        foreach ($table as $className => $methods) {
            foreach ($methods as $methodName => $meta) {
                $check = BasicBlockHelper::append($context, $tag.'_check_'.$seq);
                $match = BasicBlockHelper::append($context, $tag.'_match_'.$seq);
                $fallthrough = BasicBlockHelper::append($context, $tag.'_next_'.$seq);
                $context->builder->positionAtEnd($next);
                $context->builder->branch($check);
                $context->builder->positionAtEnd($check);
                self::emitClassMethodMatchBranch(
                    $context,
                    $classCstr,
                    $methodCstr,
                    $className,
                    $methodName,
                    $match,
                    $fallthrough
                );
                $count = $required ? (int) ($meta['required'] ?? 0) : (int) ($meta['params'] ?? 0);
                $context->builder->positionAtEnd($match);
                $context->builder->store($sizeT->constInt($count, false), $resultSlot);
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

    private static function emitClassMethodMatchBranch(
        Context $context,
        $classCstr,
        $methodCstr,
        string $className,
        string $methodName,
        $matchBlock,
        $missBlock
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $strcmp = $context->lookupFunction('strcmp');

        $classExpected = $context->constantFromString($className);
        $classEq = $context->builder->call(
            $strcmp,
            $classCstr,
            $context->builder->pointerCast($classExpected, $i8p)
        );
        $classOk = $context->builder->icmp(Builder::INT_EQ, $classEq, $i32->constInt(0, false));

        $methodExpected = $context->constantFromString($methodName);
        $methodEq = $context->builder->call(
            $strcmp,
            $methodCstr,
            $context->builder->pointerCast($methodExpected, $i8p)
        );
        $methodOk = $context->builder->icmp(Builder::INT_EQ, $methodEq, $i32->constInt(0, false));
        $bothOk = $context->builder->and($classOk, $methodOk);
        $context->builder->branchIf($bothOk, $matchBlock, $missBlock);
    }

    /**
     * @return array<string, array<string, array{params: int, required: int}>>
     */
    private static function decodeTable(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $className => $methods) {
            if (!\is_string($className) || '' === $className || !\is_array($methods)) {
                continue;
            }
            foreach ($methods as $methodName => $meta) {
                if (!\is_string($methodName) || '' === $methodName || !\is_array($meta)) {
                    continue;
                }
                $out[$className][$methodName] = [
                    'params' => (int) ($meta['params'] ?? 0),
                    'required' => (int) ($meta['required'] ?? 0),
                ];
            }
        }

        return $out;
    }
}
