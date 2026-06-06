<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\VM\AttributeSupport;

/**
 * Compile-time map of user attribute class flags (#6912, Zend zend_mark_attribute_repeatable).
 */
final class AttributeClassRegistry
{
    /** @var array<string, int> lc attribute class name => #[Attribute] flags */
    private array $flags = [];

    /**
     * @param list<AttributeEntry> $selfAttributeEntries attribute metadata on the declaring class
     */
    public function registerAttributeClass(string $className, array $selfAttributeEntries): void
    {
        $flags = self::extractSelfAttributeFlags($selfAttributeEntries);
        if (null === $flags) {
            return;
        }
        $this->flags[self::lc($className)] = $flags;
    }

    public function isRepeatable(string $attributeClassName): bool
    {
        $flags = $this->flags[self::lc($attributeClassName)] ?? null;
        if (null === $flags) {
            return false;
        }

        return 0 !== ($flags & AttributeSupport::IS_REPEATABLE);
    }

    /**
     * @param list<AttributeEntry> $entries
     */
    public static function extractSelfAttributeFlags(array $entries): ?int
    {
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            if (!self::isAttributeMetaClass($entry->name)) {
                continue;
            }
            foreach ($entry->args as $arg) {
                if (\is_int($arg['value'])) {
                    return $arg['value'];
                }
            }

            return AttributeSupport::TARGET_ALL;
        }

        return null;
    }

    private static function isAttributeMetaClass(string $name): bool
    {
        $base = ltrim($name, '\\');
        $lc = strtolower($base);

        return 'attribute' === $lc || str_ends_with($lc, '\\attribute');
    }

    private static function lc(string $name): string
    {
        return strtolower(ltrim($name, '\\'));
    }
}
