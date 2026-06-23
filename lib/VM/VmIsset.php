<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\Web\Superglobals;

/**
 * Shared isset()/empty() semantic guards for VM + JIT lowering (#10170).
 *
 * php-src: Zend/zend_compile.c ZEND_ISSET_ISEMPTY_*; Zend/zend_execute.c isset handlers
 */
final class VmIsset
{
    /**
     * isset($obj->prop) on array-like container is always false (Zend zend_isset_dim).
     */
    public static function issetOnPropertyRejectsArrayContainer(
        JitVariable $container,
        ?Operand $containerOp,
        bool $issetOnProperty
    ): bool {
        if (!$issetOnProperty) {
            return false;
        }
        $isArrayContainer = ($container->type & JitVariable::IS_NATIVE_ARRAY) !== 0
            || JitVariable::TYPE_HASHTABLE === $container->type
            || (
                JitVariable::TYPE_VALUE === $container->type
                && null !== $containerOp
                && null !== $containerOp->type
                && Type::TYPE_ARRAY === $containerOp->type->type
            );

        return $isArrayContainer;
    }

    /**
     * Stored instance property is considered set when defined and not null (#3298).
     */
    public static function storedPropertyIsSet(Variable $value): bool
    {
        $value = $value->resolveIndirect();
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return false;
        }

        return true;
    }

    public static function literalStringKey(?Operand $dimOp): ?string
    {
        if (null === $dimOp) {
            return null;
        }
        while ($dimOp instanceof Temporary) {
            if (null === $dimOp->original) {
                return null;
            }
            $dimOp = $dimOp->original;
        }
        if (!$dimOp instanceof Literal) {
            return null;
        }
        if (null === $dimOp->type || Type::TYPE_STRING !== $dimOp->type->type) {
            return null;
        }

        return is_string($dimOp->value) ? $dimOp->value : null;
    }

    public static function superglobalName(
        JitVariable $container,
        ?Operand $containerOp,
        bool $selfHostAot
    ): ?string {
        if (null !== $container->superglobalName) {
            return $container->superglobalName;
        }
        if ($selfHostAot) {
            return null;
        }
        $name = OperandName::resolve($containerOp);
        if (null !== $name && Superglobals::isSuperglobalName($name)) {
            return $name;
        }
        if ($containerOp instanceof Literal && Superglobals::isSuperglobalName($containerOp->value)) {
            return $containerOp->value;
        }

        return null;
    }

    public static function isSelfHostAot(): bool
    {
        $flag = getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }
}
