<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Zend-style class/interface/trait validation (issue #144).
 *
 * php-src: Zend/zend_inheritance.c, zend_compile.c abstract/interface checks.
 */
final class ClassValidator
{
    public static function finalizeClassDefinition(
        ClassEntry $entry,
        Context $context,
        ?Frame $frame = null
    ): void {
        if ($entry->isInterface || $entry->isTrait) {
            return;
        }

        // Before abstract-method fatals — zend_implement_serializable (Zend/zend_interfaces.c; #22000).
        self::maybeDeprecateLegacySerializable($entry, $context, $frame);

        self::rebuildAbstractMethods($entry, $context);
        self::validateInterfaceImplementation($entry, $context);
        self::validateInterfaceProperties($entry, $context);
        self::validateAbstractMethodsResolved($entry, $context);
        self::validateAbstractPropertyHooksResolved($entry, $context);
    }

    /**
     * Zend zend_implement_serializable — E_DEPRECATED unless both __serialize and __unserialize exist.
     *
     * php-src: Zend/zend_interfaces.c (PHP 8.1+). Skips explicit abstract / internal classes.
     */
    private static function maybeDeprecateLegacySerializable(
        ClassEntry $entry,
        Context $context,
        ?Frame $frame = null
    ): void {
        if ($entry->isAbstract || $entry->isInternal) {
            return;
        }
        if (!\in_array('serializable', $entry->interfaces, true)) {
            return;
        }
        if (isset($entry->methods['__serialize'], $entry->methods['__unserialize'])) {
            return;
        }

        $file = null;
        $line = 0;
        if (null !== $entry->sourceLocation) {
            if ('' !== $entry->sourceLocation->filename) {
                $file = $entry->sourceLocation->filename;
            }
            $line = $entry->sourceLocation->startLine > 0 ? $entry->sourceLocation->startLine : 0;
        }

        $context->errors->internalDeprecated(
            $entry->name.' implements the Serializable interface, which is deprecated. '
            .'Implement __serialize() and __unserialize() instead (or in addition, if support for old PHP versions is necessary)',
            $context,
            $frame,
            $file,
            $line
        );
    }

    public static function assertInstantiable(ClassEntry $entry): void
    {
        if ($entry->isEnum) {
            throw new \Error("Cannot instantiate enum {$entry->name}");
        }
        if ($entry->isInterface) {
            throw new \Error("Cannot instantiate interface {$entry->name}");
        }
        if ($entry->isTrait) {
            throw new \Error("Cannot instantiate trait {$entry->name}");
        }
        if ($entry->isStatic) {
            throw new \Error("Cannot instantiate static class {$entry->name}");
        }
        if ($entry->isAbstract || [] !== $entry->abstractMethods) {
            throw new \Error("Cannot instantiate abstract class {$entry->name}");
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
        if ($entry->isAbstract) {
            return;
        }

        $missing = [];
        foreach ($entry->interfaces as $ifaceLc) {
            foreach (self::collectInterfaceMethods($ifaceLc, $context) as $method) {
                if (!self::classProvidesMethod($entry, $method, $context)) {
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

        // php-src: zend_verify_abstract_class — E_COMPILE_ERROR at DECLARE (#25912).
        throw new \CompileError(
            "Class {$entry->name} contains {$count} abstract method"
            .(1 === $count ? '' : 's')
            ." and must therefore be declared abstract or implement the remaining methods ({$ifaceName}::{$method})"
        );
    }

    /**
     * Concrete classes must declare properties required by implemented interfaces (#28374, re-#6965/#6770).
     *
     * Same-script omissions are caught by {@see \PHPCompiler\Compiler\InterfaceImplementationCheck};
     * require/include splits only see the interface ClassEntry at DECLARE time (zend_inheritance.c).
     */
    private static function validateInterfaceProperties(ClassEntry $entry, Context $context): void
    {
        if ($entry->isAbstract) {
            return;
        }

        $missing = [];
        foreach ($entry->interfaces as $ifaceLc) {
            foreach (self::collectInterfaceProperties($ifaceLc, $context) as [$ifaceDisplay, $propDisplay, $propLc]) {
                if (self::classProvidesProperty($entry, $propLc, $context)) {
                    continue;
                }
                $missing[] = [
                    $ifaceDisplay,
                    $propDisplay,
                    self::interfacePropertyHookSummary($ifaceLc, $propDisplay, $context),
                ];
            }
        }

        if ([] === $missing) {
            return;
        }

        $count = count($missing);
        $list = implode(', ', array_map(
            static fn (array $triple): string => $triple[0].'::$'.$triple[1].$triple[2],
            $missing
        ));

        // php-src: zend_do_implement_interface / property hook obligations — E_COMPILE_ERROR at DECLARE.
        throw new \CompileError(
            "Class {$entry->name} must implement {$count} interface propert"
            .(1 === $count ? 'y' : 'ies')
            ." ({$list})"
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> iface display, prop display, prop lc
     */
    private static function collectInterfaceProperties(string $ifaceLc, Context $context): array
    {
        $required = [];
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
            $ifaceDisplay = self::shortClassDisplayName($iface->name);
            foreach ($iface->properties as $prop) {
                $propLc = strtolower($prop->name);
                if (!isset($required[$propLc])) {
                    $required[$propLc] = [$ifaceDisplay, $prop->name, $propLc];
                }
            }
            foreach ($iface->interfaces as $parentIface) {
                $queue[] = $parentIface;
            }
        }

        return array_values($required);
    }

    private static function classProvidesProperty(ClassEntry $entry, string $propLc, Context $context): bool
    {
        $visited = [];
        $current = $entry;
        while (!isset($visited[strtolower($current->name)])) {
            $visited[strtolower($current->name)] = true;
            foreach ($current->properties as $prop) {
                if (strtolower($prop->name) === $propLc) {
                    return true;
                }
            }
            $parentLc = $current->parentLc;
            if (null === $parentLc || '' === $parentLc || !isset($context->classes[$parentLc])) {
                break;
            }
            $current = $context->classes[$parentLc];
        }

        return false;
    }

    private static function interfacePropertyHookSummary(string $ifaceLc, string $propName, Context $context): string
    {
        $meta = $context->propertyHookRegistry[$ifaceLc][$propName]
            ?? $context->propertyHookRegistry[$ifaceLc][strtolower($propName)]
            ?? [];
        $hooks = [];
        if (!empty($meta['requiresGet'])) {
            $hooks[] = 'get';
        }
        if (!empty($meta['requiresSet'])) {
            $hooks[] = 'set';
        }
        if (!empty($meta['requiresUnset'])) {
            $hooks[] = 'unset';
        }
        if ([] === $hooks) {
            return '';
        }

        return ' { '.implode('; ', $hooks).'; }';
    }

    private static function shortClassDisplayName(string $name): string
    {
        $trim = ltrim($name, '\\');
        if (!str_contains($trim, '\\')) {
            return $trim;
        }
        $parts = explode('\\', $trim);

        return end($parts) ?: $trim;
    }

    private static function validateAbstractMethodsResolved(ClassEntry $entry, Context $context): void
    {
        if ($entry->isAbstract || [] === $entry->abstractMethods) {
            return;
        }

        $count = count($entry->abstractMethods);
        $list = [];
        foreach ($entry->abstractMethods as $methodLc => $_) {
            $origin = self::abstractMethodOriginDisplay($entry, (string) $methodLc, $context);
            $methodDisplay = $entry->methodNames[$methodLc]
                ?? self::ancestorMethodDisplayName($entry, (string) $methodLc, $context)
                ?? (string) $methodLc;
            $list[] = $origin.'::'.$methodDisplay;
        }

        // php-src: zend_verify_abstract_class — cite declaring class::method (#30022, #25912).
        throw new \CompileError(
            "Class {$entry->name} contains {$count} abstract method"
            .(1 === $count ? '' : 's')
            .' and must therefore be declared abstract or implement the remaining methods ('
            .implode(', ', $list).')'
        );
    }

    /**
     * Zend cites the abstract method's declaring class, not the incomplete concrete child (#30022).
     *
     * Own / trait abstracts stay on the child (C::f); inherited abstracts walk to the furthest
     * ancestor that still lists the method as abstract (A::f through intermediate abstract parents).
     */
    private static function abstractMethodOriginDisplay(
        ClassEntry $entry,
        string $methodLc,
        Context $context
    ): string {
        $origin = $entry;
        $parentLc = $entry->parentLc;
        while (null !== $parentLc && isset($context->classes[$parentLc])) {
            $parent = $context->classes[$parentLc];
            if (!isset($parent->abstractMethods[$methodLc])) {
                break;
            }
            $origin = $parent;
            $parentLc = $parent->parentLc;
        }

        return self::shortClassDisplayName($origin->name);
    }

    private static function ancestorMethodDisplayName(
        ClassEntry $entry,
        string $methodLc,
        Context $context
    ): ?string {
        $parentLc = $entry->parentLc;
        while (null !== $parentLc && isset($context->classes[$parentLc])) {
            $parent = $context->classes[$parentLc];
            if (isset($parent->methodNames[$methodLc])) {
                return $parent->methodNames[$methodLc];
            }
            $parentLc = $parent->parentLc;
        }

        return null;
    }

    private static function validateAbstractPropertyHooksResolved(ClassEntry $entry, Context $context): void
    {
        $missing = AbstractPropertyHookCheck::missingForClass($entry, $context);
        if ([] === $missing) {
            return;
        }

        $count = count($missing);
        $list = implode(', ', array_map(
            static fn (array $pair): string => $pair[0].'::'.$pair[1],
            $missing
        ));

        // php-src: zend_verify_abstract_class — E_COMPILE_ERROR at DECLARE (#25912).
        throw new \CompileError(
            "Class {$entry->name} contains {$count} abstract method"
            .(1 === $count ? '' : 's')
            ." and must therefore be declared abstract or implement the remaining methods ({$list})"
        );
    }

    /**
     * Zend zend_class_implements_interface — concrete method on class or ancestor (#14756, #14757).
     */
    private static function classProvidesMethod(ClassEntry $entry, string $methodLc, Context $context): bool
    {
        $visited = [];
        $current = $entry;
        while (!isset($visited[strtolower($current->name)])) {
            $visited[strtolower($current->name)] = true;
            if (isset($current->methods[$methodLc]) && !isset($current->abstractMethods[$methodLc])) {
                return true;
            }
            $parentLc = $current->parentLc;
            if (null === $parentLc || '' === $parentLc || !isset($context->classes[$parentLc])) {
                break;
            }
            $current = $context->classes[$parentLc];
        }

        return false;
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
