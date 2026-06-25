<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for spl_classes() (issue #11802). */
final class JitSplClasses
{
    public static function invoke(Context $context): Value
    {
        $names = [];
        $vm = $context->runtime->vmContext;
        if (null !== $vm) {
            $names = array_keys(VmSplRegistry::classesMap($vm));
        }

        $ht = HashTableHelper::alloc($context);
        foreach ($names as $name) {
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            $val = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($name))
            );
            HashTableHelper::setAtStringKey($context, $ht, $keyStr, $val);
        }

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
