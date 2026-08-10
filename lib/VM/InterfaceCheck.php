<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Runtime interface satisfaction for intersection type hints (#1357).
 */
final class InterfaceCheck
{
    /**
     * @param list<string> $interfaceLcs
     */
    public static function assertObjectImplementsAll(
        Variable $value,
        array $interfaceLcs,
        Context $context,
        string $kind = 'Argument',
        ?string $expectedDisplay = null
    ): void {
        if ([] === $interfaceLcs) {
            return;
        }
        $expected = $expectedDisplay ?? self::formatIntersectionType($interfaceLcs);
        $target = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $target->type) {
            $given = self::valueTypeName($target);
            self::throwKindTypeError($kind, $expected, $given, $value);

            return;
        }
        $entry = $target->toObject()->class;
        foreach ($interfaceLcs as $memberLc) {
            if (!self::entrySatisfiesIntersectionMember($entry, $memberLc, $context)) {
                // Fallback message path (no UserParamErrorContext): strip anon NUL (#29569).
                $given = \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($entry->name);

                self::throwKindTypeError($kind, $expected, $given, $value);
            }
        }
    }

    private static function throwKindTypeError(
        string $kind,
        string $expected,
        string $given,
        Variable $value
    ): void {
        if ('Argument' === $kind) {
            $ctx = TypeCheck::currentParamErrorContext();
            if (null !== $ctx) {
                $ctx->throwExpectedType($expected, $value);
            }
        }

        throw new \TypeError("{$kind} must be of type {$expected}, {$given} given");
    }

    /**
     * Intersection member satisfaction: instanceof for classes, implements for interfaces (zend_verify_arg_intersection_type).
     */
    public static function entrySatisfiesIntersectionMember(
        ClassEntry $entry,
        string $memberLc,
        Context $context
    ): bool {
        $memberLc = self::resolveClassAliasLc(strtolower(ltrim($memberLc, '\\')), $context);
        $memberEntry = $context->classes[$memberLc] ?? null;
        if (null !== $memberEntry && $memberEntry->isInterface) {
            return self::entryImplements($entry, $memberLc, $context);
        }

        return self::entryIsInstanceOf($entry, $memberLc, $context);
    }

    public static function entryImplements(ClassEntry $entry, string $ifaceLc, Context $context): bool
    {
        $ifaceLc = self::resolveClassAliasLc(strtolower(ltrim($ifaceLc, '\\')), $context);
        $visited = [];
        $current = $entry;
        while (null !== $current) {
            $lc = strtolower($current->name);
            if (isset($visited[$lc])) {
                break;
            }
            $visited[$lc] = true;
            foreach ($current->interfaces as $impl) {
                if (self::interfaceLcExtends($impl, $ifaceLc, $context)) {
                    return true;
                }
            }
            if ($current->isInterface && $lc === $ifaceLc) {
                return true;
            }
            if (null === $current->parentLc) {
                break;
            }
            if (!isset($context->classes[$current->parentLc])) {
                break;
            }
            $current = $context->classes[$current->parentLc];
        }

        if (StringableSupport::isStringableInterfaceLc($ifaceLc)
            && StringableSupport::entryHasImplicitStringable($entry, $context)) {
            return true;
        }

        return false;
    }

    public static function entryIsInstanceOf(ClassEntry $entry, string $classLc, Context $context): bool
    {
        $classLc = self::resolveClassAliasLc(strtolower(ltrim($classLc, '\\')), $context);
        $visited = [];
        $current = $entry;
        while (null !== $current) {
            $lc = strtolower($current->name);
            if (isset($visited[$lc])) {
                break;
            }
            $visited[$lc] = true;
            if ($lc === $classLc) {
                if (ResourceSupport::isHiddenPseudoClassEntry($current)) {
                    return false;
                }

                return true;
            }
            $wantEntry = $context->classes[$classLc] ?? null;
            if (null !== $wantEntry && $current === $wantEntry) {
                return true;
            }
            foreach ($current->interfaces as $impl) {
                if (self::interfaceLcExtends($impl, $classLc, $context)) {
                    return true;
                }
            }
            if ($current->isInterface && $lc === $classLc) {
                return true;
            }
            if (null === $current->parentLc) {
                break;
            }
            if (!isset($context->classes[$current->parentLc])) {
                break;
            }
            $current = $context->classes[$current->parentLc];
        }

        if (StringableSupport::isStringableInterfaceLc($classLc)
            && StringableSupport::entryHasImplicitStringable($entry, $context)) {
            return true;
        }

        return false;
    }

    /**
     * True when $ifaceLc is $wantLc or extends it via interface inheritance (zend_inheritance.c, #4754).
     */
    public static function interfaceLcExtends(string $ifaceLc, string $wantLc, Context $context): bool
    {
        $ifaceLc = strtolower(ltrim($ifaceLc, '\\'));
        $wantLc = self::resolveClassAliasLc(strtolower(ltrim($wantLc, '\\')), $context);
        if ($ifaceLc === $wantLc) {
            return true;
        }
        $visited = [];
        $stack = [$ifaceLc];
        while ([] !== $stack) {
            $current = array_pop($stack);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if ($current === $wantLc) {
                return true;
            }
            $entry = $context->classes[$current] ?? null;
            if (null === $entry) {
                continue;
            }
            foreach ($entry->interfaces as $parent) {
                $parent = strtolower(ltrim($parent, '\\'));
                if (!isset($visited[$parent])) {
                    $stack[] = $parent;
                }
            }
        }

        return false;
    }

    private static function resolveClassAliasLc(string $lc, Context $context): string
    {
        while (isset($context->classAliases[$lc])) {
            $lc = $context->classAliases[$lc];
        }

        return $lc;
    }

    /**
     * @param list<string> $interfaceLcs
     */
    private static function formatIntersectionType(array $interfaceLcs): string
    {
        return implode('&', $interfaceLcs);
    }

    private static function valueTypeName(Variable $value): string
    {
        return EnumCaseSupport::typeNameForVariable($value);
    }
}
