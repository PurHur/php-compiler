<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op\Stmt;
use PHPCompiler\MethodVisibility;

/**
 * Built-in Stringable interface and compile-time checks (issue #3296, Zend zend_interfaces.c).
 */
final class StringableSupport
{
    public const INTERFACE_NAME = 'Stringable';
    public const INTERFACE_LC = 'stringable';

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry(self::INTERFACE_NAME);
        $entry->isInterface = true;
        $ctx->classes[self::INTERFACE_LC] = $entry;
    }

    /**
     * @param list<string> $interfaceLcs
     */
    public static function requiresImplementation(array $interfaceLcs): bool
    {
        return in_array(self::INTERFACE_LC, $interfaceLcs, true);
    }

    public static function assertConcreteClassImplements(Stmt\Class_ $class, string $className): void
    {
        if (0 !== ($class->flags & \PhpParser\Node\Stmt\Class_::MODIFIER_ABSTRACT)) {
            return;
        }
        if (self::classDeclHasConcreteToString($class)) {
            return;
        }
        $display = ltrim($className, '\\');
        throw new \CompileError(
            "Class {$display} contains 1 abstract method and must therefore be declared abstract"
            .' or implement the remaining methods (Stringable::__toString)'
        );
    }

    public static function classDeclHasConcreteToString(Stmt\Class_ $class): bool
    {
        foreach ($class->stmts->children as $stmt) {
            if (!$stmt instanceof Stmt\ClassMethod) {
                continue;
            }
            if ('__tostring' !== strtolower($stmt->func->name)) {
                continue;
            }
            if (0 !== ($stmt->func->flags & CfgFunc::FLAG_ABSTRACT)) {
                continue;
            }
            if (!MethodVisibility::isPublic(MethodVisibility::mask($stmt->func->flags))) {
                continue;
            }

            return true;
        }

        return false;
    }
}
