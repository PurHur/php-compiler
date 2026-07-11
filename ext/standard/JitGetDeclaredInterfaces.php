<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_declared_interfaces() (issue #3176). */
final class JitGetDeclaredInterfaces
{
    public static function invoke(Context $context, bool $excludeDeprecated = false): Value
    {
        if ($excludeDeprecated && null !== $context->runtime->vmContext) {
            $names = self::namesFromVmTable(
                VmReflection::declaredInterfacesTable($context->runtime->vmContext, true)
            );

            return self::wrapHashTable($context, self::emitInterfaceNames($context, $names));
        }

        $names = $context->type->object->allDeclaredInterfaceNames();
        if ([] === $names && null !== $context->runtime->vmContext) {
            $names = self::namesFromVmTable(
                VmReflection::declaredInterfacesTable($context->runtime->vmContext, false)
            );
        }

        return self::wrapHashTable(
            $context,
            self::emitInterfaceNames($context, $names)
        );
    }

    /**
     * @return list<string>
     */
    private static function namesFromVmTable(\PHPCompiler\VM\HashTable $table): array
    {
        $names = [];
        foreach ($table->iterate(true) as $valueVar) {
            $resolved = $valueVar->resolveIndirect();
            if (VMVariable::TYPE_STRING === $resolved->type) {
                $names[] = $resolved->toString();
            }
        }

        return $names;
    }

    /**
     * @param list<string> $names
     */
    private static function emitInterfaceNames(Context $context, array $names): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($names as $index => $ifaceName) {
            $jit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($ifaceName))
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
