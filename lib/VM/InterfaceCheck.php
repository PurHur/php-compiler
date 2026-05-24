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
        string $kind = 'Argument'
    ): void {
        if ([] === $interfaceLcs) {
            return;
        }
        $target = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $target->type) {
            $expected = self::formatIntersectionType($interfaceLcs);
            $given = self::valueTypeName($target);

            throw new \TypeError("{$kind} must be of type {$expected}, {$given} given");
        }
        $entry = $target->toObject()->class;
        foreach ($interfaceLcs as $ifaceLc) {
            if (!self::entryImplements($entry, $ifaceLc, $context)) {
                $expected = self::formatIntersectionType($interfaceLcs);
                $given = $entry->name;

                throw new \TypeError(
                    "{$kind} must be of type {$expected}, {$given} given"
                );
            }
        }
    }

    public static function entryImplements(ClassEntry $entry, string $ifaceLc, Context $context): bool
    {
        $visited = [];
        $current = $entry;
        while (null !== $current) {
            $lc = strtolower($current->name);
            if (isset($visited[$lc])) {
                break;
            }
            $visited[$lc] = true;
            if (in_array($ifaceLc, $current->interfaces, true)) {
                return true;
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

        return false;
    }

    public static function entryIsInstanceOf(ClassEntry $entry, string $classLc, Context $context): bool
    {
        $visited = [];
        $current = $entry;
        while (null !== $current) {
            $lc = strtolower($current->name);
            if (isset($visited[$lc])) {
                break;
            }
            $visited[$lc] = true;
            if ($lc === $classLc) {
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

        return false;
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
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
