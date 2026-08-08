<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for class_alias() (issues #3095, #3178, #6583). */
final class JitClassAlias
{
    /** php-src Zend/zend_builtin_functions.c — zif_class_alias internal-class ValueError (#29150). */
    public const INTERNAL_CLASS_VALUE_ERROR =
        'class_alias(): Argument #1 ($class) must be a user-defined class name, internal class name given';

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

        $i1 = $context->getTypeFromString('int1');
        // PROFILE≤8.2: emit catchable ValueError in IR (do not throw in the host compiler) (#29150).
        if (!CompilerVersion::allowsClassAliasOfInternalClass()
            && self::originalIsInternalClass($context, $original)) {
            ExceptionBridge::emitValueErrorAndAbort($context, self::INTERNAL_CLASS_VALUE_ERROR);

            return $i1->constInt(0, false);
        }

        $object = $context->type->object;
        $originalLc = strtolower(ltrim($original, '\\'));
        $aliasLc = strtolower(ltrim($alias, '\\'));
        $vmContext = $context->runtime->vmContext;
        // AOT/standalone compile keeps user classes in this module's Object_ registry,
        // not the host vmContext. Prefer Object_ when the original is already declared
        // there so class_alias + class_exists literal folding agree (#27010).
        $inCompileUnit = null !== $object->classIdForLowerName($originalLc);
        if ($inCompileUnit || null === $vmContext) {
            $ok = $object->registerClassAlias($original, $alias);
            if ($ok && null !== $vmContext && isset($vmContext->classes[$originalLc])
                && !isset($vmContext->classes[$aliasLc])
                && !isset($vmContext->classAliases[$aliasLc])) {
                $vmContext->registerClassAlias($original, $alias, false);
            }
        } else {
            $ok = $vmContext->registerClassAlias($original, $alias, $autoload);
            if ($ok) {
                $object->registerClassAlias($original, $alias);
            }
        }

        return $i1->constInt($ok ? 1 : 0, false);
    }

    /** Whether the class_alias() original resolves to an internal class/interface (#29150). */
    private static function originalIsInternalClass(Context $context, string $original): bool
    {
        $originalLc = strtolower(ltrim($original, '\\'));
        $object = $context->type->object;
        $classId = $object->classIdForLowerName($originalLc);
        if (null !== $classId && $object->isExternalOnlyClass($classId)) {
            return true;
        }

        $vmContext = $context->runtime->vmContext;
        if (null === $vmContext || !isset($vmContext->classes[$originalLc])) {
            return false;
        }
        $canonicalOriginalLc = $originalLc;
        while (isset($vmContext->classAliases[$canonicalOriginalLc])) {
            $canonicalOriginalLc = $vmContext->classAliases[$canonicalOriginalLc];
        }

        return $vmContext->classes[$canonicalOriginalLc]->isInternal;
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
