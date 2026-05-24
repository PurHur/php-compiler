<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for class_exists() (issues #1214, #1056). */
final class JitClassExists
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::classExistsLiteral($context, $literal);
        }

        $nameStr = JitStringArg::lower($context, $nameArg, 'class_exists() class name');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $exists = $i1->constInt(0, false);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $nameData = self::stringDataPtr($context, $nameStr);

        $candidates = $context->type->object->allDeclaredClassLowerNames();
        if (null !== $context->runtime->vmContext) {
            $candidates = array_values(array_unique(array_merge(
                $candidates,
                array_keys($context->runtime->vmContext->classes)
            )));
        }

        foreach ($candidates as $lc) {
            $candidate = $context->builder->load($context->constantStringFromString($lc));
            $candidateData = self::stringDataPtr($context, $candidate);
            $cmp = $context->builder->call($strcasecmpFn, $nameData, $candidateData);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $exists = $context->builder->or($exists, $match);
        }

        return $exists;
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }
}
