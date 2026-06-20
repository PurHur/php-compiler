<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\SuperglobalNames;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\VmVarFetch;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for $$name guards via VmVarFetch PHP (#10289, #8708).
 *
 * php-src: Zend/zend_execute.c — ZEND_FETCH_R/W superglobal branch
 * SSOT: {@see \PHPCompiler\VM\VmVarFetch}
 */
final class VarFetchRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__var_fetch__isSuperglobalName');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__var_fetch__isSuperglobalName', $probe);

            return;
        }

        self::implementSuperglobalBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function callIsSuperglobalName(Context $context, Value $namePtr): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__var_fetch__isSuperglobalName');

        return $context->builder->call($fn, $namePtr);
    }

    public static function isSuperglobalNameAtCompileTime(string $name): bool
    {
        return VmVarFetch::isSuperglobalName($name);
    }

    private static function implementSuperglobalBridge(Context $context): void
    {
        $abiName = '__var_fetch__isSuperglobalName';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureStrcmp($context);

        $strPtr = $context->getTypeFromString('string*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('var_fetch_superglobal_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $name = $fn->getParam(0);
        $falseVal = $i1->constInt(0, false);
        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $name->typeOf()->constNull());
        $nullBb = $fn->appendBasicBlock('var_fetch_sg_null');
        $checkBb = $fn->appendBasicBlock('var_fetch_sg_check');
        $context->builder->branchIf($nullName, $nullBb, $checkBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($falseVal);

        $context->builder->positionAtEnd($checkBb);
        $hit = $falseVal;
        foreach (SuperglobalNames::ALL as $literal) {
            $litGlobal = $context->constantFromString($literal);
            $litPtr = $context->builder->pointerCast($litGlobal, $strPtr);
            $cmp = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $name,
                $litPtr
            );
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $cmp->typeOf()->constInt(0, false)
            );
            $hit = $context->builder->or($hit, $match);
        }
        $context->builder->returnValue($hit);
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureStrcmp(Context $context): void
    {
        try {
            $context->lookupFunction('strcmp');
        } catch (\Throwable) {
            $strPtr = $context->getTypeFromString('string*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $strPtr, $strPtr);
            $fn = $context->module->addFunction('strcmp', $ft);
            $context->registerFunction('strcmp', $fn);
        }
    }
}
