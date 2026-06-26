<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\GlobalIntrospectionNameRuntime;
use PHPCompiler\JIT\Builtin\StringFunctionExists;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for function_exists() (issue #1216). */
final class JitFunctionExists
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            $literal = VmReflection::normalizeGlobalIntrospectionName($literal);

            return $i1->constInt($context->functionIsRegistered($literal) ? 1 : 0, false);
        }

        $nameStr = JitStringBuiltinArg::lower($context, $nameArg, 'function_exists', 0, 'function');
        $nameStr = GlobalIntrospectionNameRuntime::normalizeString($context, $nameStr);
        StringFunctionExists::ensureLinked($context);
        $builtinHit = $context->builder->call(
            $context->lookupFunction('__compiler_builtin_function_exists'),
            $nameStr
        );
        $i64 = $context->getTypeFromString('int64');
        $exists = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $builtinHit,
            $i64->constInt(0, false)
        );
        foreach ($context->userFunctionNames() as $fn) {
            $candidate = $context->builder->load($context->constantStringFromString($fn));
            $match = JitStringCompare::identical($context, $nameStr, $candidate);
            $exists = $context->builder->or($exists, $match);
        }

        return $exists;
    }
}
