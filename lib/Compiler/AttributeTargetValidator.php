<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\AttributeSupport;

/**
 * Attribute declaration-site guards (Zend zend_attributes.c).
 *
 * Builtin meta-attributes ({@see AttributeNames}, #[Attribute] TARGET_CLASS) still
 * compile-fatal on wrong sites. User {@see Attribute::TARGET_*} mismatches are
 * stored for Reflection and enforced only in {@see ReflectionAttribute::newInstance}
 * (#25729, #23528) — matching php-src, not a compile-time fatal.
 *
 * Promoted constructor parameters still run this pass after the class body is parsed
 * (remap to TARGET_PROPERTY) so #[Attribute] meta stays consistent (#5124 timing).
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
     * @param list<AttributeEntry> $entries
     */
    public static function assertPromotedParameterTargets(
        array $entries,
        AttributeClassRegistry $registry
    ): void {
        if (!CompilerVersion::supportsDelayedTargetValidationAttribute()) {
            return;
        }

        self::assertEntriesForTarget(
            $entries,
            AttributeSupport::TARGET_PROPERTY,
            'property',
            $registry,
            false
        );
    }

    /**
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

        $delayInternal = $delayInternalValidation
            && CompilerVersion::supportsDelayedTargetValidationAttribute()
            && self::hasDelayedTargetValidation($entries);

        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            if (self::isBuiltinInternalAttribute($entry->name)) {
                if ($delayInternal) {
                    continue;
                }

                continue;
            }
            // Builtin #[Attribute] is TARGET_CLASS only (zend_attributes.c / #25723).
            // Do not skip — wrong sites must compile-fatal like Zend.
            if (self::isAttributeMetaClass($entry->name)) {
                if (AttributeSupport::TARGET_CLASS !== $targetFlag) {
                    throw new \CompileError(
                        'Attribute "'.self::messageName($entry->name).'" cannot target '.$targetLabel
                        .' (allowed targets: class)'
                    );
                }
                continue;
            }

            // User Attribute::TARGET_* mismatches are not compile-fatal (zend_attributes.c).
            // Keep storing attributes for Reflection; newInstance() throws Error (#25729 / #23528).
            // $registry remains available to callers for flags/repeatable checks elsewhere.
        }
    }

    /**
     * @param list<AttributeEntry> $entries
     */
    private static function hasDelayedTargetValidation(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $base = strtolower(ltrim($entry->name, '\\'));
            if ('delayedtargetvalidation' === $base || str_ends_with($base, '\\delayedtargetvalidation')) {
                return true;
            }
        }

        return false;
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
     */
    public static function formatAllowedTargets(int $flags): string
    {
        $names = [];
        foreach (self::targetLabels() as $flag => $label) {
            if (0 !== ($flags & $flag)) {
                $names[] = $label;
            }
        }

        return [] !== $names ? implode(', ', $names) : 'none';
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
