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
        // Zend zend_compile.c: duplicate rejection at compile time applies only to
        // internal attributes without IS_REPEATABLE (#18709, zend_internal_attribute_get).
        if (self::isInternalNonRepeatable($attributeClassName)) {
            return false;
        }

        return true;
    }

    /** Internal compiler attributes that reject duplicates at compile time (Zend/zend_attributes.stub.php). */
    public static function isInternalNonRepeatable(string $attributeClassName): bool
    {
        $base = strtolower(ltrim($attributeClassName, '\\'));
        $pos = strrpos($base, '\\');
        $short = false !== $pos ? substr($base, $pos + 1) : $base;

        return \in_array($short, [
            'attribute',
            'allowdynamicproperties',
            'sensitiveparameter',
            'returntypewillchange',
        ], true);
    }

    public function getFlags(string $attributeClassName): ?int
    {
        return $this->flags[self::lc($attributeClassName)] ?? null;
    }

    public function allowsTarget(string $attributeClassName, int $targetFlag): bool
    {
        $flags = $this->getFlags($attributeClassName);
        if (null === $flags) {
            return true;
        }

        return 0 !== ($flags & $targetFlag);
    }

    /**
     * True when the declaring class/interface/trait/enum carries #[Attribute] (#6450).
     *
     * @param list<AttributeEntry> $entries
     */
    public static function isRegisteredAttributeClass(array $entries): bool
    {
        return null !== self::extractSelfAttributeFlags($entries);
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
