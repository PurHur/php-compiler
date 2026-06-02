<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_declared_classes() (issue #3128). */
final class JitGetDeclaredClasses
{
    public static function invoke(Context $context): Value
    {
        $names = $context->type->object->allDeclaredClassNames();
        if ([] === $names && null !== $context->runtime->vmContext) {
            $table = VmReflection::declaredClassesTable($context->runtime->vmContext);
            foreach ($table->iterate(true) as $valueVar) {
                $resolved = $valueVar->resolveIndirect();
                if (VMVariable::TYPE_STRING === $resolved->type) {
                    $names[] = $resolved->toString();
                }
            }
        }

        return self::wrapHashTable(
            $context,
            self::emitClassNames($context, $names)
        );
    }

    /**
     * @param list<string> $names
     */
    private static function emitClassNames(Context $context, array $names): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($names as $index => $className) {
            $jit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($className))
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
