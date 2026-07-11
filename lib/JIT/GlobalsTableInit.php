<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\UndefinedGlobalVariableRuntime;
use PHPLLVM\Builder;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * JIT $GLOBALS symbol table — aliases script-global value boxes (issue #4423, #3413, #3601).
 */
final class GlobalsTableInit
{
    public static function ensureGlobal(Context $context, string $name): Variable
    {
        return $context->ensureScriptGlobal($name);
    }

    public static function hasGlobal(Context $context, string $name): bool
    {
        return isset($context->jitGlobalVariables[$name]);
    }

    public static function offsetFetch(Context $context, Variable $key, bool $forWrite): Variable
    {
        $name = self::resolveStringKey($context, $key);
        if (null === $name) {
            throw new \LogicException('$GLOBALS[] JIT lowering requires a compile-time string key (issue #4423)');
        }
        if (!$forWrite && !self::hasGlobal($context, $name)) {
            UndefinedGlobalVariableRuntime::emitWarningForName($context, $name);
        }

        return self::ensureGlobal($context, $name);
    }

    public static function offsetIsSet(Context $context, Variable $key): \PHPLLVM\Value
    {
        $name = self::resolveStringKey($context, $key);
        if (null === $name || !self::hasGlobal($context, $name)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $global = $context->jitGlobalVariables[$name];
        $ptr = JitValueBox::valuePtrFromVariable($context, $global);
        $typeByte = $context->builder->load(
            $context->builder->structGep(
                $ptr,
                $context->structFieldMap['__value__']['type']
            )
        );
        $nullTag = $context->getTypeFromString('int8')->constInt(
            Variable::jitTypeByteFromVmType(VmVariable::TYPE_NULL),
            false
        );

        return $context->builder->icmp(Builder::INT_NE, $typeByte, $nullTag);
    }

    private static function resolveStringKey(Context $context, Variable $key): ?string
    {
        if (null !== $key->compileTimeString && Variable::TYPE_STRING === $key->type) {
            return $key->compileTimeString;
        }

        return null;
    }
}
