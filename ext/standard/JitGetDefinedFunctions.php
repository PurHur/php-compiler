<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\JIT\Builtin\GetDefinedFunctionsRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_defined_functions() (issue #3128). */
final class JitGetDefinedFunctions
{
    public static function invoke(Context $context): Value
    {
        GetDefinedFunctionsRuntime::ensureLinked($context);
        $userNames = self::collectUserFunctionNames($context);
        $userHt = self::emitUserFunctionNames($context, $userNames);
        $rootHt = $context->builder->call(
            $context->lookupFunction('__compiler_get_defined_functions_merge'),
            $userHt
        );

        return self::wrapHashTable($context, $rootHt);
    }

    /**
     * @return list<string>
     */
    private static function collectUserFunctionNames(Context $context): array
    {
        $names = [];
        foreach ($context->userFunctionNames() as $lc) {
            $names[$lc] = $lc;
        }
        if (null !== $context->runtime->vmContext) {
            foreach ($context->runtime->vmContext->functions as $func) {
                if ($func instanceof FuncInternal) {
                    continue;
                }
                $names[strtolower($func->getName())] = $func->getName();
            }
        }

        return array_values($names);
    }

    /**
     * @param list<string> $names
     */
    private static function emitUserFunctionNames(Context $context, array $names): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($names as $index => $name) {
            $jit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($name))
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $context->getTypeFromString('int64')->constInt($index, false),
                $jit
            );
        }

        return $ht;
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
