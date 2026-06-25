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
        BuiltinClasses::registerBuiltinInterfaceMethods($entry, ['__toString']);
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

    public static function isStringableInterfaceLc(string $ifaceLc): bool
    {
        return self::INTERFACE_LC === strtolower(ltrim($ifaceLc, '\\'));
    }

    /**
     * Zend zend_interfaces.c — public concrete __toString satisfies Stringable without explicit implements (#7198).
     */
    public static function entryHasImplicitStringable(ClassEntry $entry, Context $context): bool
    {
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            return false;
        }
        $decl = self::resolveConcreteToStringDecl($entry, $context);
        if (null === $decl) {
            return false;
        }
        [$declaring, $methodLc] = $decl;
        $vis = $declaring->methodVisibility[$methodLc] ?? CfgFunc::FLAG_PUBLIC;

        return MethodVisibility::isPublic($vis);
    }

    /**
     * @return array{0: ClassEntry, 1: string}|null
     */
    private static function resolveConcreteToStringDecl(ClassEntry $entry, Context $context): ?array
    {
        $methodLc = '__tostring';
        $lcClass = strtolower($entry->name);
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($context->classes[$lcClass])) {
                break;
            }
            $current = $context->classes[$lcClass];
            if (isset($current->methods[$methodLc])) {
                return [$current, $methodLc];
            }
            if (isset($current->abstractMethods[$methodLc])) {
                return null;
            }
            if (null === $current->parentLc) {
                break;
            }
            $lcClass = $current->parentLc;
        }

        return null;
    }
}
