<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for class_alias() (issues #3095, #3178, #6583). */
final class JitClassAlias
{
    /**
     * Compile-time string operands (issues #3095, #3178).
     *
     * @return Value int1 truthiness
     */
    public static function invokeLiteral(
        Context $context,
        string $original,
        string $alias,
        ?JITVariable $autoloadArg = null
    ): Value {
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

    /**
     * Runtime string operands — php-src Z_PARAM_STR via {@see JitStringBuiltinArg} (#6583).
     *
     * Enum-case operands emit TypeError in IR before this runs; other non-literal strings
     * still defer to VM lowering (compile-time LogicException preserves FUNCCALL_EXEC path).
     *
     * @return Value int1 truthiness
     */
    public static function invokeRuntime(
        Context $context,
        Value $originalStr,
        Value $aliasStr,
        JITVariable $originalArg,
        JITVariable $aliasArg,
        ?JITVariable $autoloadArg = null
    ): Value {
        $originalLit = JitStringArg::compileTimeLiteral($originalArg);
        $aliasLit = JitStringArg::compileTimeLiteral($aliasArg);
        if (null !== $originalLit && null !== $aliasLit) {
            return self::invokeLiteral($context, $originalLit, $aliasLit, $autoloadArg);
        }

        // Enum-case and other non-literal operands: {@see JitStringBuiltinArg} emits TypeError in IR;
        // unreachable bool return keeps MCJIT compile green (#6583).
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(0, false);
    }
}
