<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_defined_functions() / get_declared_functions() (issue #3128). */
final class JitGetDefinedFunctions
{
    public static function invoke(Context $context, bool $excludeDisabled = false): Value
    {
        $internalHt = self::emitFunctionNames(
            $context,
            VmReflection::internalFunctionNameList($excludeDisabled)
        );
        $userHt = self::emitFunctionNames($context, self::collectUserFunctionNames($context));

        $rootHt = HashTableHelper::alloc($context);
        $setHashtable = $context->lookupFunction('__hashtable__setStringKeyHashtable');
        $context->builder->call(
            $setHashtable,
            $rootHt,
            $context->builder->load($context->constantStringFromString('internal')),
            $internalHt
        );
        $context->builder->call(
            $setHashtable,
            $rootHt,
            $context->builder->load($context->constantStringFromString('user')),
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
    private static function emitFunctionNames(Context $context, array $names): Value
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
