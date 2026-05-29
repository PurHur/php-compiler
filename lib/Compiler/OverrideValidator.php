<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op\Stmt\ClassMethod;

/**
 * PHP 8.3 #[\Override] compile-time validation (Zend zend_compile_override_attribute).
 */
final class OverrideValidator
{
    public static function hasOverrideAttribute(array $attributeNames): bool
    {
        foreach ($attributeNames as $name) {
            $normalized = strtolower(ltrim($name, '\\'));
            if ('override' === $normalized || str_ends_with($normalized, '\\override')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \CompileError
     */
    public static function validateClassBody(
        CfgBlock $stmts,
        string $className,
        ?string $parentLc,
        array $interfaceLcs,
        ClassCompileRegistry $registry
    ): void {
        foreach ($stmts->children as $child) {
            if (!$child instanceof ClassMethod) {
                continue;
            }
            $attributeNames = AttributeNames::fromOp($child);
            if (!self::hasOverrideAttribute($attributeNames)) {
                continue;
            }
            $methodLc = strtolower($child->func->name);
            if (!$registry->hasOverridableMethod($parentLc, $interfaceLcs, $methodLc)) {
                throw new \CompileError(sprintf(
                    '%s::%s() has #[\Override] attribute, but no matching parent method exists',
                    ltrim($className, '\\'),
                    $child->func->name
                ));
            }
        }
    }
}
