<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for class_alias() (issues #3095, #3178). */
final class JitClassAlias
{
    public static function invoke(Context $context, JITVariable $originalArg, JITVariable $aliasArg, ?JITVariable $autoloadArg = null): Value
    {
        $original = JitStringArg::compileTimeLiteral($originalArg);
        $alias = JitStringArg::compileTimeLiteral($aliasArg);
        if (null === $original || null === $alias) {
            throw new \LogicException(
                'class_alias() original and alias must be compile-time strings in this compiler build'
            );
        }
        $autoload = true;
        if (null !== $autoloadArg) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $autoloadArg->type || null === $autoloadArg->value->value) {
                throw new \LogicException('class_alias() autoload must be a boolean literal in this compiler build');
            }
            $autoload = 0 !== (int) $context->llvm->lib->LLVMConstIntGetZExtValue($autoloadArg->value->value);
        }

        $vmContext = $context->runtime->vmContext;
        if (null === $vmContext) {
            $ok = $context->type->object->registerClassAlias($original, $alias);
        } else {
            $ok = $vmContext->registerClassAlias($original, $alias, $autoload);
            if ($ok) {
                $context->type->object->registerClassAlias($original, $alias);
            }
        }

        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($ok ? 1 : 0, false);
    }
}
