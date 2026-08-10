<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\VM\AttributeSupport;

/**
 * Attribute declaration-site helpers (Zend zend_attributes.c).
 *
 * User {@see Attribute::TARGET_*} mismatches are **not** compile-fatal: php-src stores the
 * attribute and {@see \ReflectionAttribute::newInstance()} throws Error (#25729 / #23528).
 * Builtin internal attributes keep dedicated compile-time guards in {@see AttributeNames}.
 *
 * Builtin {@see \Attribute} itself is TARGET_CLASS only — wrong sites still compile-fatal (#25723).
 */
final class AttributeTargetValidator
{
    /** @return array<int, string> */
    private static function targetLabels(): array
    {
        $labels = [
            AttributeSupport::TARGET_CLASS => 'class',
            AttributeSupport::TARGET_FUNCTION => 'function',
            AttributeSupport::TARGET_METHOD => 'method',
            AttributeSupport::TARGET_PROPERTY => 'property',
            AttributeSupport::TARGET_CLASS_CONSTANT => 'class constant',
            AttributeSupport::TARGET_PARAMETER => 'parameter',
        ];
        if (AttributeSupport::hasTargetConstant()) {
            $labels[AttributeSupport::TARGET_CONSTANT] = 'constant';
        }

        return $labels;
    }

    /**
     * Promoted constructor parameters: user TARGET_* mismatches deferred to newInstance (#25729).
     *
     * @param list<AttributeEntry> $entries
     */
    public static function assertPromotedParameterTargets(
        array $entries,
        AttributeClassRegistry $registry
    ): void {
        self::assertEntriesForTarget(
            $entries,
            AttributeSupport::TARGET_PROPERTY,
            'property',
            $registry,
            false
        );
    }

    /**
     * Declaration-site hook for attribute lists on a given TARGET_* site.
     *
     * User attributes: no CompileError — Zend validates at ReflectionAttribute::newInstance (#25729).
     * Builtin {@see \Attribute}: TARGET_CLASS only — wrong sites CompileError (#25723).
     * Other builtin / meta attributes: {@see AttributeNames} enforces wrong-site fatals.
     *
     * @param list<AttributeEntry> $entries
     */
    public static function assertEntriesForTarget(
        array $entries,
        int $targetFlag,
        string $targetLabel,
        AttributeClassRegistry $registry,
        bool $delayInternalValidation
    ): void {
        if ([] === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            if (self::isBuiltinInternalAttribute($entry->name)) {
                continue;
            }
            // Builtin #[Attribute] is TARGET_CLASS only (zend_attributes.c / #25723).
            if (self::isAttributeMetaClass($entry->name)) {
                if (AttributeSupport::TARGET_CLASS !== $targetFlag) {
                    throw new \CompileError(
                        'Attribute "'.self::messageName($entry->name).'" cannot target '.$targetLabel
                        .' (allowed targets: class)'
                    );
                }
                continue;
            }

            // User Attribute::TARGET_* mismatches: defer to ReflectionAttribute::newInstance (#25729).
            // Do not compile-fatal here under php-src-strict.
        }
    }

    private static function isBuiltinInternalAttribute(string $name): bool
    {
        $base = strtolower(ltrim($name, '\\'));
        $pos = strrpos($base, '\\');
        $short = false !== $pos ? substr($base, $pos + 1) : $base;

        return \in_array($short, [
            'override',
            'allowdynamicproperties',
            'compiletime',
            'sensitiveparameter',
            'nodiscard',
            'deprecated',
            'returntypewillchange',
            'delayedtargetvalidation',
        ], true);
    }

    private static function isAttributeMetaClass(string $name): bool
    {
        $base = ltrim($name, '\\');
        $lc = strtolower($base);

        return 'attribute' === $lc || str_ends_with($lc, '\\attribute');
    }

    /** Short name for Zend Error / CompileError attribute messages. */
    public static function messageName(string $name): string
    {
        $name = ltrim($name, '\\');
        $pos = strrpos($name, '\\');

        return false !== $pos ? substr($name, $pos + 1) : $name;
    }

    /** Human label for a single Attribute::TARGET_* bit (declaration site). */
    public static function labelForTargetFlag(int $targetFlag): string
    {
        return self::targetLabels()[$targetFlag] ?? 'unknown';
    }

    /**
     * Comma-separated allowed-target labels (IS_REPEATABLE bit ignored).
     *
     * php-src: zend_attributes.c / ReflectionAttribute::newInstance Error text.
     * Empty mask (Attribute(0)) yields '' so the message reads "(allowed targets: )" (#29918).
     */
    public static function formatAllowedTargets(int $flags): string
    {
        $names = [];
        foreach (self::targetLabels() as $flag => $label) {
            if (0 !== ($flags & $flag)) {
                $names[] = $label;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Zend Error message when ReflectionAttribute::newInstance() sees a wrong target (#23528).
     *
     * php-src: ext/reflection/php_reflection.c — ZEND_METHOD(ReflectionAttribute, newInstance)
     */
    public static function runtimeWrongTargetMessage(string $attrName, int $siteTarget, int $allowedFlags): string
    {
        return 'Attribute "'.self::messageName($attrName).'" cannot target '
            .self::labelForTargetFlag($siteTarget)
            .' (allowed targets: '.self::formatAllowedTargets($allowedFlags).')';
    }
}
