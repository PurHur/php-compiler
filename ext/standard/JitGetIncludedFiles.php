<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_included_files() / get_required_files() (issue #3315). */
final class JitGetIncludedFiles
{
    public static function invoke(Context $context): Value
    {
        $paths = $context->jitIncludedFiles;
        if ([] === $paths && null !== $context->runtime->vmContext) {
            foreach ($context->runtime->vmContext->includedFiles() as $path) {
                $paths[] = $path;
            }
        }

        return self::wrapHashTable($context, self::emitPaths($context, $paths));
    }

    /**
     * @param list<string> $paths
     */
    private static function emitPaths(Context $context, array $paths): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($paths as $index => $path) {
            $jit = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($path))
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
