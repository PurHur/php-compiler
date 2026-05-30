<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend-style class/interface/trait validation (issue #144).
 *
 * php-src: Zend/zend_inheritance.c, zend_compile.c abstract/interface checks.
 */
final class ClassValidator
{
    public static function finalizeClassDefinition(ClassEntry $entry, Context $context): void
    {
        if ($entry->isInterface || $entry->isTrait) {
            return;
        }

        self::rebuildAbstractMethods($entry, $context);
        self::validateInterfaceImplementation($entry, $context);
        self::validateAbstractMethodsResolved($entry);
    }

    public static function assertInstantiable(ClassEntry $entry): void
    {
        if ($entry->isAbstract || [] !== $entry->abstractMethods) {
            throw new \LogicException("Cannot instantiate abstract class {$entry->name}");
        }
    }

    private static function rebuildAbstractMethods(ClassEntry $entry, Context $context): void
    {
        $abstract = [];
        foreach ($entry->abstractMethods as $name => $_) {
            $abstract[$name] = true;
        }

        $parentLc = $entry->parentLc;
        while (null !== $parentLc && isset($context->classes[$parentLc])) {
            $parent = $context->classes[$parentLc];
            foreach ($parent->abstractMethods as $name => $_) {
                $abstract[$name] = true;
            }
            $parentLc = $parent->parentLc;
        }

        foreach ($entry->methods as $name => $_) {
            if (!isset($entry->abstractMethods[$name])) {
                unset($abstract[$name]);
            }
        }

        $entry->abstractMethods = $abstract;
    }

    private static function validateInterfaceImplementation(ClassEntry $entry, Context $context): void
    {
        $missing = [];
        foreach ($entry->interfaces as $ifaceLc) {
            foreach (self::collectInterfaceMethods($ifaceLc, $context) as $method) {
                if (!isset($entry->methods[$method]) || isset($entry->abstractMethods[$method])) {
                    $missing[] = [$ifaceLc, $method];
                }
            }
        }

        if ([] === $missing) {
            return;
        }

        $count = count($missing);
        [$ifaceLc, $method] = $missing[0];
        $ifaceName = $context->classes[$ifaceLc]->name ?? $ifaceLc;

        throw new \LogicException(
            "Class {$entry->name} contains {$count} abstract method"
            .(1 === $count ? '' : 's')
            ." and must therefore be declared abstract or implement the remaining methods ({$ifaceName}::{$method})"
        );
    }

    private static function validateAbstractMethodsResolved(ClassEntry $entry): void
    {
        if ($entry->isAbstract || [] === $entry->abstractMethods) {
            return;
        }

        $count = count($entry->abstractMethods);
        $first = array_key_first($entry->abstractMethods);

        throw new \LogicException(
            "Class {$entry->name} contains {$count} abstract method"
            .(1 === $count ? '' : 's')
            ." and must therefore be declared abstract or implement the remaining methods ({$entry->name}::{$first})"
        );
    }

    /**
     * @return list<string>
     */
    private static function collectInterfaceMethods(string $ifaceLc, Context $context): array
    {
        $methods = [];
        $visited = [];
        $queue = [$ifaceLc];
        while ([] !== $queue) {
            $lc = array_shift($queue);
            if (isset($visited[$lc])) {
                continue;
            }
            $visited[$lc] = true;
            if (!isset($context->classes[$lc])) {
                continue;
            }
            $iface = $context->classes[$lc];
            if (!$iface->isInterface) {
                continue;
            }
            foreach ($iface->methods as $name => $_) {
                $methods[$name] = true;
            }
            foreach ($iface->abstractMethods as $name => $_) {
                $methods[$name] = true;
            }
            foreach ($iface->interfaces as $parentIface) {
                $queue[] = $parentIface;
            }
        }

        return array_keys($methods);
    }
}
