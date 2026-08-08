<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCompiler\CompilerVersion;
use PHPCfg\Op;

/**
 * Extract declared PHP 8 attribute class names from CFG op metadata (#1936).
 */
final class AttributeNames
{
    /**
     * @return list<string> Fully-qualified attribute names as written in source.
     */
    public static function fromOp(Op $op): array
    {
        if (!$op->hasAttribute('attrGroups')) {
            return [];
        }
        $groups = $op->getAttribute('attrGroups');
        if (!\is_array($groups)) {
            return [];
        }

        return self::fromAttrGroups($groups);
    }

    /**
     * @param list<\PhpParser\Node\AttributeGroup> $groups
     *
     * @return list<string>
     */
    public static function fromAttrGroups(array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $names[] = $attr->name->toString();
            }
        }

        return $names;
    }

    /** True when `#[\AllowDynamicProperties]` is present (#3467). */
    public static function hasAllowDynamicProperties(array $attributeNames): bool
    {
        foreach ($attributeNames as $name) {
            if ('AllowDynamicProperties' === ltrim($name, '\\')) {
                return true;
            }
        }

        return false;
    }

    /** True when `#[\Override]` is present (#6864). */
    public static function hasOverride(array $names): bool
    {
        foreach ($names as $name) {
            $normalized = strtolower(ltrim($name, '\\'));
            if ('override' === $normalized || str_ends_with($normalized, '\\override')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard (zend_attributes.c / zend_attributes.stub.php; #6864, #25138, #26253).
     *
     * `#[\Override]` targets methods from PHP 8.3+. Property targeting requires PHP 8.5+
     * ({@see CompilerVersion::supportsOverridePropertyTarget}). Class constants are not a valid
     * target on any shipping PHP (RFC override_constants is proposed for 8.6 only).
     *
     * With `#[\DelayedTargetValidation]`, wrong-target errors defer to newInstance (#26329).
     * Functional override checks are unaffected.
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertOverrideMethodTargetOnly(array $names, string $target, ?array $entries = null): void
    {
        if (!CompilerVersion::supportsOverrideAttribute()) {
            return;
        }

        if (!self::hasOverride($names)) {
            return;
        }

        $allowed = ['method'];
        $allowedMsg = 'method';
        if (CompilerVersion::supportsOverridePropertyTarget()) {
            $allowed[] = 'property';
            $allowedMsg = 'method, property';
        }

        if (\in_array($target, $allowed, true)) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasOverride([$name]),
            'Attribute "'.self::messageName('Override').'" cannot target '.$target
            .' (allowed targets: '.$allowedMsg.')'
        );
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #5137).
     * `#[\AllowDynamicProperties]` is only valid on classes.
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertAllowDynamicPropertiesClassTargetOnly(array $names, string $target, ?array $entries = null): void
    {
        if (!self::hasAllowDynamicProperties($names)) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasAllowDynamicProperties([$name]),
            'Attribute "'.self::messageName('AllowDynamicProperties').'" cannot target '.$target.' (allowed targets: class)'
        );
    }

    /** True when `#[\Attribute]` (the meta-attribute) is present (#25723). */
    public static function hasAttributeMetaClass(array $names): bool
    {
        foreach ($names as $name) {
            $normalized = strtolower(ltrim((string) $name, '\\'));
            if ('attribute' === $normalized || str_ends_with($normalized, '\\attribute')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #25723).
     * `#[\Attribute]` itself is TARGET_CLASS only — reject on functions/methods/etc.
     *
     * Call only from non-class declaration sites (same pattern as
     * {@see assertAllowDynamicPropertiesClassTargetOnly}).
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertAttributeMetaClassTargetOnly(array $names, string $target, ?array $entries = null): void
    {
        if (!self::hasAttributeMetaClass($names)) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasAttributeMetaClass([$name]),
            'Attribute "'.self::messageName('Attribute').'" cannot target '.$target.' (allowed targets: class)'
        );
    }

    /** True when `#[\DelayedTargetValidation]` is present (#26241 / PHP 8.5). */
    public static function hasDelayedTargetValidation(array $names): bool
    {
        foreach ($names as $name) {
            $normalized = strtolower(ltrim((string) $name, '\\'));
            if ('delayedtargetvalidation' === $normalized
                || str_ends_with($normalized, '\\delayedtargetvalidation')) {
                return true;
            }
        }

        return false;
    }

    /**
     * PROFILE≥8.5 with `#[\DelayedTargetValidation]` on the same declaration (#26329).
     *
     * php-src: Zend/zend_attributes.c — delays internal attribute target / custom-validator
     * CompileErrors until ReflectionAttribute::newInstance().
     *
     * @param list<string> $names
     */
    public static function shouldDelayInternalTargetValidation(array $names): bool
    {
        return CompilerVersion::supportsDelayedTargetValidationAttribute()
            && self::hasDelayedTargetValidation($names);
    }

    /**
     * Emit an internal-attribute validation failure, or store it on matching entries when delayed (#26329).
     *
     * @param list<AttributeEntry>|null $entries mutated when deferring ({@see AttributeEntry::$validationError})
     * @param callable(string): bool    $matchesAttr
     */
    public static function deferOrThrowInternalValidationError(
        ?array $entries,
        array $names,
        callable $matchesAttr,
        string $message,
    ): void {
        if (self::shouldDelayInternalTargetValidation($names) && null !== $entries) {
            foreach ($entries as $entry) {
                if ($entry instanceof AttributeEntry && $matchesAttr($entry->name)) {
                    $entry->validationError = $message;
                }
            }

            return;
        }

        throw new \CompileError($message);
    }

    /**
     * PHP 8.5+ Zend compile-time guard (zend_attributes.c {@code validate_attribute}, #26241).
     *
     * `#[\Attribute]` on abstract class / interface / trait / enum is a CompileError unless
     * `#[\DelayedTargetValidation]` is also present (error stored for ReflectionAttribute::newInstance).
     *
     * @param list<AttributeEntry> $entries mutated when deferring (sets {@see AttributeEntry::$validationError})
     * @param 'abstract class'|'interface'|'trait'|'enum' $kind
     */
    public static function assertAttributeMetaOnConcreteClassLike(
        array $entries,
        string $kind,
        string $display,
    ): void {
        if (!CompilerVersion::rejectsAttributeOnNonConcreteClassLike()) {
            return;
        }
        $names = AttributeEntry::namesFromList($entries);
        if (!self::hasAttributeMetaClass($names)) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasAttributeMetaClass([$name]),
            'Cannot apply #[\\Attribute] to '.$kind.' '.$display
        );
    }

    /**
     * Zend compile-time guard (zend_compile.c, issue #7299).
     * `#[\AllowDynamicProperties]` and `readonly class` are mutually exclusive.
     *
     * @param list<string> $names
     */
    public static function assertAllowDynamicPropertiesNotOnReadonlyClass(array $names, string $classDisplay): void
    {
        if (!self::hasAllowDynamicProperties($names)) {
            return;
        }

        throw new \CompileError(
            'Cannot apply #[AllowDynamicProperties] to readonly class '.$classDisplay
        );
    }

    /**
     * Zend compile-time guard (zend_attributes.c, php-src GH-15731, issue #9734 / #17402).
     * `#[\AllowDynamicProperties]` is rejected on enums from PHP 8.5+; Zend 8.2 accepts silently.
     *
     * @param list<string> $names
     */
    public static function assertAllowDynamicPropertiesNotOnEnum(array $names, string $enumDisplay): void
    {
        if (!\PHPCompiler\CompilerVersion::rejectsAllowDynamicPropertiesOnEnum()) {
            return;
        }

        if (!self::hasAllowDynamicProperties($names)) {
            return;
        }

        throw new \CompileError(
            'Cannot apply #[AllowDynamicProperties] to enum '.$enumDisplay
        );
    }

    /** True when `#[\Deprecated]` is present (#22989). */
    public static function hasDeprecated(array $names): bool
    {
        foreach ($names as $name) {
            $base = ltrim((string) $name, '\\');
            if ('Deprecated' === $base || str_ends_with($base, '\\Deprecated')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard for #[\Deprecated] (zend_attributes.c, #23701).
     *
     * PHP 8.4: function | method | class constant only.
     * PHP 8.5+: Attribute::TARGET_CLASS (class-likes: class/interface/trait/enum) and
     * TARGET_CONSTANT are advertised — {@see assertDeprecatedAllowedOnClassLike} then
     * restricts TARGET_CLASS applications to traits only (rfc:deprecated_traits, #26307).
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertDeprecatedTargetAllowed(array $names, string $target, ?array $entries = null): void
    {
        if (!CompilerVersion::advertisesDeprecatedAttributeClass()) {
            return;
        }
        if (!self::hasDeprecated($names)) {
            return;
        }

        if (CompilerVersion::supportsDeprecatedTraitAttribute()) {
            $allowed = ['class', 'function', 'method', 'class constant', 'constant'];
            $allowedMsg = 'class, function, method, class constant, constant';
        } else {
            $allowed = ['function', 'method', 'class constant'];
            $allowedMsg = 'function, method, class constant';
        }

        if (\in_array($target, $allowed, true)) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasDeprecated([$name]),
            'Attribute "'.self::messageName('Deprecated').'" cannot target '.$target
            .' (allowed targets: '.$allowedMsg.')'
        );
    }

    /**
     * Zend 8.5+ validate_deprecated: #[\Deprecated] on class-likes is traits-only
     * (zend_attributes.c, rfc:deprecated_traits, #22989 / #26307 / #26329 / #28892).
     *
     * TARGET_CLASS on the builtin is required so traits pass the Attribute target mask;
     * this validator then fatals for class / interface / enum (same message shape as php-src).
     * Delayed by `#[\DelayedTargetValidation]`.
     *
     * Recurring false Done-when: accepting Deprecated on classes under PROFILE=8.5.
     * Live php-src still fatals (`Cannot apply #[\Deprecated] to class …`); keep reject.
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertDeprecatedAllowedOnClassLike(
        array $names,
        ?DeprecatedMetadata $meta,
        string $objectType,
        string $displayName,
        ?array $entries = null,
    ): void {
        if (!CompilerVersion::supportsDeprecatedTraitAttribute()) {
            return;
        }
        if (null === $meta && !self::hasDeprecated($names)) {
            return;
        }
        if ('trait' === $objectType) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasDeprecated([$name]),
            'Cannot apply #[\\Deprecated] to '.$objectType.' '.$displayName
        );
    }

    /** PHP 8.2 #[\SensitiveParameter] on parameters (issue #3351, Zend zend_attributes.c). */
    public static function isSensitiveParameter(array $names): bool
    {
        foreach ($names as $name) {
            $base = ltrim($name, '\\');
            if ('SensitiveParameter' === $base || str_ends_with($base, '\\SensitiveParameter')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #11638).
     * `#[\SensitiveParameter]` is only valid on parameters.
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertSensitiveParameterParamTargetOnly(array $names, string $target, ?array $entries = null): void
    {
        if (!self::isSensitiveParameter($names)) {
            return;
        }

        if ('parameter' === $target) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::isSensitiveParameter([$name]),
            'Attribute "'.self::messageName('SensitiveParameter').'" cannot target '.$target.' (allowed targets: parameter)'
        );
    }

    /**
     * Drop parameter-only internal attrs from the property side of constructor promotion.
     *
     * php-src zend_attributes.c / GH-9420 (#9661): #[\SensitiveParameter] stays on the
     * parameter; ReflectionProperty must not list it (#26379, re-#20351).
     *
     * @param list<AttributeEntry> $entries
     *
     * @return list<AttributeEntry>
     */
    public static function filterPromotedPropertyAttributeEntries(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            if (self::isSensitiveParameter([$entry->name])) {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** True when `#[\ReturnTypeWillChange]` is present (#25722). */
    public static function hasReturnTypeWillChange(array $names): bool
    {
        foreach ($names as $name) {
            $base = ltrim((string) $name, '\\');
            if ('ReturnTypeWillChange' === $base || str_ends_with($base, '\\ReturnTypeWillChange')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #25722).
     * `#[\ReturnTypeWillChange]` is TARGET_METHOD only — reject on functions/properties/etc.
     *
     * AttributeTargetValidator skips builtin internals (`returntypewillchange`), so this
     * dedicated AttributeNames guard is required (same pattern as SensitiveParameter).
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertReturnTypeWillChangeMethodTargetOnly(array $names, string $target, ?array $entries = null): void
    {
        if (!self::hasReturnTypeWillChange($names)) {
            return;
        }

        if ('method' === $target) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasReturnTypeWillChange([$name]),
            'Attribute "'.self::messageName('ReturnTypeWillChange').'" cannot target '.$target.' (allowed targets: method)'
        );
    }

    /** PHP 8.4+ #[\NoDiscard] on functions/methods (issue #5078, Zend zend_attributes.c). */
    public static function hasNoDiscard(array $names): bool
    {
        foreach ($names as $name) {
            $base = ltrim($name, '\\');
            if ('NoDiscard' === $base || str_ends_with($base, '\\NoDiscard')) {
                return true;
            }
        }

        return false;
    }

    /** PHP 8.4+ #[\CompileTime] on constants (issue #7300, Zend zend_attributes.c). */
    public static function hasCompileTime(array $names): bool
    {
        foreach ($names as $name) {
            $base = ltrim($name, '\\');
            if ('CompileTime' === $base || str_ends_with($base, '\\CompileTime')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #7300).
     * `#[\CompileTime]` is only valid on global and class constants.
     *
     * @param list<string>              $names
     * @param list<AttributeEntry>|null $entries
     */
    public static function assertCompileTimeConstTargetOnly(array $names, string $target, ?array $entries = null): void
    {
        if (!self::hasCompileTime($names)) {
            return;
        }

        if ('constant' === $target || 'class constant' === $target) {
            return;
        }

        self::deferOrThrowInternalValidationError(
            $entries,
            $names,
            static fn (string $name): bool => self::hasCompileTime([$name]),
            'Attribute "'.self::messageName('CompileTime').'" cannot target '.$target.' (allowed targets: class constant, constant)'
        );
    }

    /**
     * Zend compile-time duplicate guard (zend_compile.c, zend_is_attribute_repeated) (#3718, #6912).
     *
     * Allows duplicates when the attribute class declares Attribute::IS_REPEATABLE; marks
     * all instances of a repeated name with {@see AttributeEntry::$isRepeated}.
     *
     * @param list<AttributeEntry> $entries
     *
     * @return list<AttributeEntry>
     */
    public static function validateDuplicates(array $entries, AttributeClassRegistry $registry): array
    {
        $counts = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $key = strtolower(ltrim($entry->name, '\\'));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $result = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $key = strtolower(ltrim($entry->name, '\\'));
            if ($counts[$key] > 1) {
                if (!$registry->isRepeatable($entry->name)) {
                    throw new \CompileError(
                        'Attribute "'.self::messageName($entry->name).'" must not be repeated'
                    );
                }
                $result[] = new AttributeEntry($entry->name, $entry->args, true);
            } else {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $names
     *
     * @deprecated Use {@see validateDuplicates()} with {@see AttributeClassRegistry}.
     */
    public static function assertNoDuplicates(array $names): void
    {
        $registry = new AttributeClassRegistry();
        $entries = [];
        foreach ($names as $name) {
            $entries[] = new AttributeEntry($name);
        }
        self::validateDuplicates($entries, $registry);
    }

    private static function messageName(string $name): string
    {
        $name = ltrim($name, '\\');
        $pos = strrpos($name, '\\');

        return false !== $pos ? substr($name, $pos + 1) : $name;
    }
}
