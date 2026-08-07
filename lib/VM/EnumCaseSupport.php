<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\spl\SplDualIteratorStorage;
use PHPCompiler\Frame;
use PHPCompiler\RuntimeStrictness;

/**
 * Backed enum case singleton objects (#3518, Zend zend_enum.c parity).
 */
final class EnumCaseSupport
{
    public static function isEnumCase(ObjectEntry $object): bool
    {
        return $object->isEnumCase;
    }

    /**
     * Class const fetch on enum cases — upgrade legacy backing scalars to TYPE_ENUM_CASE (#5832, #5798).
     */
    /**
     * Runtime E::{$name} lookup — resolve declared case name to enum singleton (#9937, Zend/zend_enum.c).
     */
    public static function fetchCaseByMemberName(
        ClassEntry $enum,
        string $memberLc,
        Variable $dest,
        Context $context
    ): bool {
        if (!self::tryMaterializeEnumCaseConstantFetch($enum, $memberLc, $dest)) {
            return false;
        }
        $resolved = $dest->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type && self::isEnumCase($resolved->toObject())) {
            return true;
        }
        $dest->copyFrom(self::materializeConstantValue($context, $dest));

        return true;
    }

    public static function tryMaterializeEnumCaseConstantFetch(
        ClassEntry $enum,
        string $memberLc,
        Variable $dest
    ): bool {
        if (!isset($enum->constants[$memberLc])) {
            return false;
        }
        $canonical = EnumSupport::enumCaseNameForConstantMember($enum, $memberLc);
        if (null === $canonical) {
            return false;
        }
        if (null !== $enum->backedType) {
            EnumSupport::ensureBackedEnumValuesUnique($enum);
        }
        $stored = $enum->constants[$memberLc]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $stored->type && self::isEnumCase($stored->toObject())) {
            $dest->copyFrom($enum->constants[$memberLc]);

            return true;
        }
        if (Variable::TYPE_ENUM_CASE === $stored->type) {
            $dest->copyFrom($enum->constants[$memberLc]);

            return true;
        }
        $backing = new Variable(Variable::TYPE_NULL);
        $backing->null();
        if (null !== $enum->backedType) {
            $backing->copyFrom($enum->constants[$memberLc]);
        }
        $dest->enumCase(new EnumCaseEntry($enum, $canonical, $backing));

        return true;
    }

    public static function createCase(ClassEntry $enum, string $caseName, Variable $backedValue): Variable
    {
        if (!$enum->isEnum) {
            throw new \LogicException('createCase requires an enum ClassEntry');
        }
        $object = new ObjectEntry($enum);
        $object->isEnumCase = true;
        $object->enumCaseName = $caseName;
        $object->enumCaseValue = null === $enum->backedType ? null : clone $backedValue;
        $object->constructed = true;

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    /**
     * Compile-time enum case operand — TYPE_ENUM_CASE matches runtime const-fetch materialization (#9233).
     */
    public static function compileTimeCaseVariable(
        ClassEntry $enum,
        string $caseName,
        Variable $backing
    ): Variable {
        $backingCopy = new Variable();
        $backingCopy->copyFrom($backing);
        $var = new Variable(Variable::TYPE_ENUM_CASE);
        $var->enumCase(new EnumCaseEntry($enum, $caseName, $backingCopy));

        return $var;
    }

    /**
     * Enum case fetches use TYPE_ENUM_CASE; instance method dispatch needs an object receiver (#5781, #5676).
     */
    public static function receiverForInstanceMethod(Variable $receiver): Variable
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $receiver;
        }
        if (Variable::TYPE_ENUM_CASE !== $receiver->type) {
            throw new \LogicException('Method call on non-object');
        }
        $entry = $receiver->toEnumCase();
        $canonical = BackedEnum::canonicalCaseVariable($entry->enumClass, $entry->caseName);
        if (null !== $canonical) {
            $canonical = $canonical->resolveIndirect();
            if (Variable::TYPE_OBJECT === $canonical->type && self::isEnumCase($canonical->toObject())) {
                return $canonical;
            }
            if (Variable::TYPE_ENUM_CASE === $canonical->type) {
                return self::receiverForInstanceMethod($canonical);
            }
        }
        if (null === $entry->enumClass->backedType) {
            $null = new Variable();
            $null->null();

            return self::createCase($entry->enumClass, $entry->caseName, $null);
        }

        return self::createCase($entry->enumClass, $entry->caseName, $entry->backingValue);
    }

    /**
     * property_exists() on enum case objects (#5612, ext/standard/basic_functions.c zif_property_exists).
     *
     * Pseudo-properties `name` / `value` are case-sensitive like ordinary properties (#23532).
     */
    public static function propertyExistsOnCase(ClassEntry $enum, string $property): bool
    {
        if (!$enum->isEnum) {
            return false;
        }
        if ('name' === $property) {
            return true;
        }
        if ('value' === $property) {
            return null !== $enum->backedType;
        }

        return false;
    }

    /**
     * empty($case->name) / empty($case->value) — fetch magic read then apply Zend empty() (#9890, zend_enum.c).
     */
    public static function emptyPropertyOnCase(
        EnumCaseEntry $entry,
        string $property,
        ?Context $context = null,
        ?Frame $frame = null
    ): bool {
        if (!self::propertyExistsOnCase($entry->enumClass, $property)) {
            return true;
        }
        $value = $entry->fetchProperty($property, $context, $frame);

        return !ext\standard\boolval::isTruthy($value);
    }

    /**
     * Zend zend_enum_get_property_ptr_ptr — name/value are readonly pseudo-properties (#7155).
     */
    public static function isReadonlyPseudoProperty(ClassEntry $enum, string $property): bool
    {
        return self::propertyExistsOnCase($enum, $property);
    }

    /**
     * @return string|null Error message when $property is a readonly enum pseudo-property
     */
    public static function readonlyPseudoPropertyViolationMessage(
        ClassEntry $enum,
        string $property,
        bool $isUnset
    ): ?string {
        if (!self::isReadonlyPseudoProperty($enum, $property)) {
            return null;
        }
        $propLc = strtolower($property);
        if ($isUnset) {
            return "Cannot unset readonly property {$enum->name}::\${$propLc}";
        }

        return "Cannot modify readonly property {$enum->name}::\${$propLc}";
    }

    /**
     * Undeclared property write on an enum case — Zend Error, not warn-and-continue (#26588).
     *
     * php-src: Zend/zend_object_handlers.c zend_std_write_property / Zend/zend_enum.c
     */
    public static function dynamicPropertyCreateViolationMessage(
        ClassEntry $enum,
        string $property
    ): string {
        return sprintf('Cannot create dynamic property %s::$%s', $enum->name, $property);
    }

    /**
     * Property-write guard for enum cases: readonly name/value, else reject dynamic create (#26588).
     *
     * @return string|null Error message when the write must throw; null when not a write violation
     *                     (caller should not use this for reads)
     */
    public static function propertyWriteViolationMessage(
        ClassEntry $enum,
        string $property
    ): ?string {
        $readonlyMsg = self::readonlyPseudoPropertyViolationMessage($enum, $property, false);
        if (null !== $readonlyMsg) {
            return $readonlyMsg;
        }

        return self::dynamicPropertyCreateViolationMessage($enum, $property);
    }

    public static function getProperty(
        ObjectEntry $object,
        string $name,
        ?Context $context = null,
        ?Frame $frame = null
    ): Variable {
        if (!$object->isEnumCase) {
            throw new \LogicException('getProperty called on non-enum-case object');
        }
        EnumSupport::ensureBackedEnumValuesUnique(
            EnumSupport::resolveRuntimeEnumClass($context, $object->class)
        );
        $result = new Variable();
        $lc = strtolower($name);
        if ('name' === $lc) {
            $result->string($object->enumCaseName ?? '');

            return $result;
        }
        if ('value' === $lc) {
            if (null === $object->class->backedType) {
                // Unit enums have no $value; Zend uses the undefined-property path (#22523, zend_enum.c).
                self::warnUndefinedEnumProperty($object->class, $name, $context, $frame);
                $result->null();

                return $result;
            }
            if (null === $object->enumCaseValue) {
                throw new \LogicException('Backed enum case object missing backing value');
            }
            $result->copyFrom($object->enumCaseValue);

            return $result;
        }
        self::warnUndefinedEnumProperty($object->class, $name, $context, $frame);
        $result->null();

        return $result;
    }

    /**
     * Zend read_property on enum cases — E_WARNING + null (#5731, zend_enum.c).
     */
    public static function warnUndefinedEnumProperty(
        ClassEntry $enum,
        string $propertyName,
        ?Context $context,
        ?Frame $frame
    ): void {
        if (null === $context) {
            return;
        }
        $context->errors->languageWarning(
            'Undefined property: '.$enum->name.'::$'.$propertyName,
            null,
            0,
            $context,
            $frame
        );
    }

    /** Zend rejects implicit string conversion of enum case objects (#4819, #5508). */
    public static function toString(ObjectEntry $object): string
    {
        if (!$object->isEnumCase) {
            throw new \LogicException('toString called on non-enum-case object');
        }

        throw new \Error("Object of class {$object->class->name} could not be converted to string");
    }

    /**
     * Zend {@see zend_compare_enum()} (#4554, #8897).
     *
     * Identical case singleton: 0. Different cases (same enum): 1. Different enums: 1.
     * Relational <=> is not backed-scalar order — use {@see compareEnumCasesForSort()} only for sort().
     */
    public static function compareSpaceship(ObjectEntry $left, ObjectEntry $right): int
    {
        if (!$left->isEnumCase || !$right->isEnumCase) {
            throw new \LogicException('compareSpaceship requires enum case objects');
        }
        if ($left->class !== $right->class) {
            return 1;
        }
        if (($left->enumCaseName ?? '') === ($right->enumCaseName ?? '')) {
            return 0;
        }

        return 1;
    }

    /** @see EnumCaseEntry spaceship for TYPE_ENUM_CASE operands (#4554, #8897). */
    public static function compareEnumCaseEntrySpaceship(EnumCaseEntry $left, EnumCaseEntry $right): int
    {
        if ($left->enumClass !== $right->enumClass) {
            return 1;
        }
        if ($left->caseName === $right->caseName) {
            return 0;
        }

        return 1;
    }

    /**
     * Transitive ordering for sort()/asort() on enum case arrays (#5546, #5691, php-src array.c).
     *
     * php-src SORT_REGULAR uses stable object-handle order for enum zvals, not backing scalars
     * (php_array_data_compare_unstable_i / php_array_compare_transitive, PR #20517).
     */
    public static function compareEnumCasesForSort(Variable $left, Variable $right): int
    {
        [$leftClass, $leftName] = self::resolveEnumCaseIdentity($left);
        [$rightClass, $rightName] = self::resolveEnumCaseIdentity($right);
        if (null === $leftClass || null === $rightClass) {
            return 1;
        }
        if ($leftClass !== $rightClass) {
            return strcmp($leftClass->name, $rightClass->name);
        }
        if ($leftName === $rightName) {
            return 0;
        }
        // Unit enums: incomparable cases — stable sort preserves input order (php-src array.c, #16905).
        if (null === $leftClass->backedType) {
            return 0;
        }

        return self::objectIdForEnumSort($left) <=> self::objectIdForEnumSort($right);
    }

    /**
     * Stable object handle for get_object_id() / spl_object_id() on enum case operands (#5837, #8941).
     *
     * Const fetches materialize TYPE_ENUM_CASE with a compile-time ClassEntry stub; resolve the live
     * enum entry before reading the canonical singleton from {@see ClassEntry::$constants}.
     */
    public static function objectIdForVariable(Variable $value, ?Context $context = null): int
    {
        if (!self::isEnumCaseVariable($value)) {
            throw new \LogicException('objectIdForVariable requires enum case variable');
        }
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            return $value->toObject()->id;
        }
        $entry = self::enumCaseEntryForVariable($value);
        if (null === $entry) {
            throw new \LogicException('objectIdForVariable requires enum case variable');
        }
        $runtime = EnumSupport::resolveRuntimeEnumClass($context, $entry->enumClass);
        $caseVar = EnumSupport::materializeCaseForCasesList($runtime, $entry->caseName)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $caseVar->type && self::isEnumCase($caseVar->toObject())) {
            return $caseVar->toObject()->id;
        }

        return self::receiverForInstanceMethod($value)->toObject()->id;
    }

    private static function objectIdForEnumSort(Variable $value): int
    {
        [$enumClass, $caseName] = self::resolveEnumCaseIdentity($value);
        if (null !== $enumClass && '' !== $caseName) {
            $ordinal = self::enumCaseDeclarationOrdinal($enumClass, $caseName);
            if ($ordinal >= 0) {
                return $ordinal;
            }
        }
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            return $value->toObject()->id;
        }

        return -1;
    }

    /**
     * Zend {@see zend_compare()} for enum case objects (#4700).
     *
     * Same enum + same declared case name: equal. Different cases or enums: not equal.
     */
    public static function compareEquals(ObjectEntry $left, ObjectEntry $right): bool
    {
        if (!$left->isEnumCase || !$right->isEnumCase) {
            throw new \LogicException('compareEquals requires enum case objects');
        }
        if ($left->class !== $right->class) {
            return false;
        }

        return ($left->enumCaseName ?? '') === ($right->enumCaseName ?? '');
    }

    /**
     * instanceof / is_a on enum case operands (#5548, zend_enum.c).
     *
     * @return bool|null true/false when handled; null to fall through to ordinary object checks
     */
    public static function valueMatchesInstanceOfClassName(
        Variable $value,
        string $className,
        Context $context
    ): ?bool {
        $entry = self::entryForInstanceOfCheck($value);
        if (null !== $entry) {
            $className = strtolower(ltrim($className, '\\'));
            $entry = self::canonicalEnumClassEntryForInstanceOf($entry, $context);

            return InterfaceCheck::entryIsInstanceOf($entry, $className, $context)
                || InterfaceCheck::entryImplements($entry, $className, $context);
        }
        return null;
    }

    /**
     * Zend zend_hash illegal offset — objects, enum cases, and arrays cannot be keys (#5594, #6500, zend_hash.c).
     *
     * Under PROFILE≥8.3, upgrades legacy {@code Illegal offset type*} strings to
     * {@code Cannot access offset of type %s on array} (#26380; Zend/zend.c zend_illegal_container_offset).
     */
    public static function rejectIllegalArrayOffset(Variable $index, string $message = 'Illegal offset type'): void
    {
        $index = $index->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $index->type
            || Variable::TYPE_OBJECT === $index->type
            || Variable::TYPE_ARRAY === $index->type) {
            throw new \TypeError(self::illegalArrayOffsetMessage($index, $message));
        }
    }

    /**
     * php-src Zend/zend.c — zend_illegal_container_offset() message for array key writes (#26380).
     *
     * Only rewrites known Zend 8.2 legacy strings when {@see CompilerVersion::supportsTypedIllegalContainerOffset()}.
     */
    public static function illegalArrayOffsetMessage(
        Variable $index,
        string $legacyMessage = 'Illegal offset type'
    ): string {
        return self::formatIllegalContainerOffsetMessage(
            self::typeNameForVariable($index),
            $legacyMessage
        );
    }

    /**
     * Zend zend_illegal_container_offset() text from a zend_zval_type_name() label (#28628).
     *
     * Shared by VM {@see illegalArrayOffsetMessage()} and JIT compile-time key guards.
     */
    public static function formatIllegalContainerOffsetMessage(
        string $typeName,
        string $legacyMessage = 'Illegal offset type'
    ): string {
        if (!CompilerVersion::supportsTypedIllegalContainerOffset()) {
            return $legacyMessage;
        }

        return match ($legacyMessage) {
            'Illegal offset type in isset or empty' => 'Cannot access offset of type '.$typeName.' in isset or empty',
            'Illegal offset type in unset' => 'Cannot unset offset of type '.$typeName.' on array',
            'Illegal offset type' => 'Cannot access offset of type '.$typeName.' on array',
            default => $legacyMessage,
        };
    }

    public static function entryForInstanceOfCheck(Variable $value): ?ClassEntry
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return $value->toEnumCase()->enumClass;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            return $value->toObject()->class;
        }

        return null;
    }

    /**
     * Folded enum cases use a compile-time ClassEntry stub; use the declared entry when registered (#5711).
     */
    public static function canonicalEnumClassEntryForInstanceOf(ClassEntry $entry, Context $context): ClassEntry
    {
        if (!$entry->isEnum) {
            return $entry;
        }
        $lc = strtolower($entry->name);

        return $context->classes[$lc] ?? $entry;
    }

    /**
     * Backing scalar that stands in for a case when constants were materialized as scalars (#5514).
     */
    public static function scalarIsLegacyEnumCaseForClass(Variable $value, ClassEntry $enum): bool
    {
        if (!$enum->isEnum || null === $enum->backedType) {
            return false;
        }
        $value = $value->resolveIndirect();
        if (!$value->is(Variable::TYPE_INTEGER) && !$value->is(Variable::TYPE_STRING)) {
            return false;
        }
        $match = BackedEnum::tryCaseForValue($enum, $value);
        if (null === $match) {
            return false;
        }
        $caseKey = \PHPCompiler\ClassConstName::key($match->caseName);
        if (!isset($enum->constants[$caseKey])) {
            return false;
        }
        $stored = $enum->constants[$caseKey]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $stored->type && self::isEnumCase($stored->toObject())) {
            return false;
        }

        return $stored->is(Variable::TYPE_INTEGER) || $stored->is(Variable::TYPE_STRING);
    }

    /**
     * zend_compare() for variadic min()/max() on enum case operands (#5707, php-src array.c).
     *
     * Different cases of the same enum are ZEND_UNCOMPARABLE: spaceship maps that to 1, never -1.
     * Backing scalars are not consulted — max keeps the last arg that compares greater; min keeps the first.
     *
     * @return int 0 when same case, 1 when different cases (never -1)
     */
    public static function compareEnumCasesForMinMax(Variable $left, Variable $right): int
    {
        [$leftClass, $leftName] = self::resolveEnumCaseIdentity($left);
        [$rightClass, $rightName] = self::resolveEnumCaseIdentity($right);
        if (null === $leftClass || null === $rightClass || $leftClass !== $rightClass) {
            throw new \LogicException('compareEnumCasesForMinMax requires same-enum case operands');
        }
        if ($leftName === $rightName) {
            return 0;
        }

        return 1;
    }

    /**
     * Normalize operand to a canonical enum case variable when possible (#5570).
     *
     * @param ClassEntry|null $expectedEnum when set, scalars are resolved only for this enum
     */
    public static function normalizeEnumCaseOperand(
        Variable $value,
        Context $context,
        ?ClassEntry $expectedEnum = null
    ): ?Variable {
        $value = $value->resolveIndirect();
        if (self::isEnumCaseVariable($value)) {
            return $value;
        }
        if (null === $expectedEnum || null === $expectedEnum->backedType) {
            return null;
        }
        $match = BackedEnum::tryCaseForValue($expectedEnum, $value);
        if (null === $match) {
            return null;
        }

        return BackedEnum::canonicalCaseVariable($expectedEnum, $match->caseName) ?? self::enumCaseEntryToVariable($match);
    }

    public static function isEnumCaseVariable(Variable $value): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return true;
        }

        return Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject());
    }

    /**
     * Zend get_object_vars() on enum case objects — name/value pseudo-properties (#4809, ext/standard/var.c).
     *
     * @return array<string, Variable>
     */
    public static function objectVarsForCaseVariable(Variable $value): array
    {
        $value = $value->resolveIndirect();
        $result = [];
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $entry = $value->toEnumCase();
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($entry->caseName);
            $result['name'] = $nameVar;
            if (null !== $entry->enumClass->backedType) {
                $valueVar = new Variable();
                $valueVar->copyFrom($entry->backingValue);
                $result['value'] = $valueVar;
            }

            return $result;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            $object = $value->toObject();
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($object->enumCaseName ?? '');
            $result['name'] = $nameVar;
            if (null !== $object->class->backedType && null !== $object->enumCaseValue) {
                $valueVar = new Variable();
                $valueVar->copyFrom($object->enumCaseValue);
                $result['value'] = $valueVar;
            }
        }

        return $result;
    }

    public static function enumClassForCaseVariable(Variable $value): ?ClassEntry
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return $value->toEnumCase()->enumClass;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            return $value->toObject()->class;
        }

        return null;
    }

    /**
     * Zend {@see zend_type_to_string()} label for TypeError "… given" messages (#6236).
     *
     * Enum case operands surface the enum class name (E), not mixed/object.
     */
    public static function typeNameForVariable(Variable $value): string
    {
        $value = $value->resolveIndirect();
        $enumClass = self::enumClassForCaseVariable($value);
        if (null !== $enumClass) {
            return $enumClass->name;
        }

        return match ($value->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $value->toObject()->class->name,
            default => 'mixed',
        };
    }

    public static function enumCaseNameForVariable(Variable $value): string
    {
        $entry = self::enumCaseEntryForVariable($value);

        return null !== $entry ? $entry->caseName : '';
    }

    /**
     * Normalize TYPE_ENUM_CASE and enum-case TYPE_OBJECT operands for compare (#7006).
     */
    public static function enumCaseEntryForVariable(Variable $value): ?EnumCaseEntry
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return $value->toEnumCase();
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            $object = $value->toObject();
            $backing = new Variable(Variable::TYPE_NULL);
            $backing->null();
            if (null !== $object->class->backedType && null !== $object->enumCaseValue) {
                $backing->copyFrom($object->enumCaseValue);
            }

            return new EnumCaseEntry($object->class, $object->enumCaseName ?? '', $backing);
        }

        return null;
    }

    private static function enumCaseEntryToVariable(EnumCaseEntry $entry): Variable
    {
        return self::createCase($entry->enumClass, $entry->caseName, $entry->backingValue);
    }

    private static function backingValueForMinMax(Variable $value): Variable
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $backing = new Variable();
            $backing->copyFrom($value->toEnumCase()->backingValue);

            return $backing;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            $object = $value->toObject();
            if (null === $object->enumCaseValue) {
                throw new \LogicException('Backed enum case object missing backing value');
            }
            $backing = new Variable();
            $backing->copyFrom($object->enumCaseValue);

            return $backing;
        }

        throw new \LogicException('backingValueForMinMax requires enum case variable');
    }

    private static function enumCaseDeclarationOrdinal(ClassEntry $enum, string $caseName): int
    {
        foreach ($enum->enumCases as $index => $case) {
            if ($case['name'] === $caseName) {
                return $index;
            }
        }

        return -1;
    }

    /** Loose/identical == for TYPE_OBJECT / TYPE_ENUM_CASE enum operands (#4700). */
    public static function enumCaseVariablesEqual(Variable $left, Variable $right): bool
    {
        [$leftClass, $leftName] = self::resolveEnumCaseIdentity($left);
        [$rightClass, $rightName] = self::resolveEnumCaseIdentity($right);
        if (null === $leftClass || null === $rightClass) {
            return false;
        }

        return strcasecmp(ltrim($leftClass->name, '\\'), ltrim($rightClass->name, '\\')) === 0
            && $leftName === $rightName;
    }

    /**
     * @return array{0: ?ClassEntry, 1: string}
     */
    private static function resolveEnumCaseIdentity(Variable $var): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            $entry = $var->toEnumCase();

            return [$entry->enumClass, $entry->caseName];
        }
        if (Variable::TYPE_OBJECT === $var->type && self::isEnumCase($var->toObject())) {
            $object = $var->toObject();

            return [$object->class, $object->enumCaseName ?? ''];
        }

        return [null, ''];
    }

    /**
     * Runtime script-scope / $GLOBALS assign — keep live object identity (#17722).
     *
     * Deep detach via {@see materializeConstantValue()} is for define()/class const (#17676).
     */
    public static function materializeGlobalVariableValue(Context $context, Variable $src): Variable
    {
        if ($src->is(Variable::TYPE_INDIRECT) || $src->is(Variable::TYPE_PROPERTY_HOOK_REF)) {
            $out = new Variable();
            $out->copyFrom($src);

            return $out;
        }
        $src = $src->resolveIndirect();
        if ($src->is(Variable::TYPE_OBJECT)) {
            $out = new Variable();
            $out->copyFrom($src);

            return $out;
        }
        if ($src->is(Variable::TYPE_ARRAY)) {
            if (self::arrayContainsRuntimeRefs($src)) {
                $out = new Variable();
                $out->copyFrom($src);

                return $out;
            }

            return ClassConstMaterializer::detachConstantValue(
                self::materializeConstantArrayDeep($context, $src)
            );
        }

        return self::materializeConstantValue($context, $src);
    }

    /**
     * Store const/define/class-const values as immortal enum case objects (#5738, zend_constants.c).
     *
     * Converts legacy backing scalars from enum case constant tables to canonical singletons.
     */
    public static function materializeConstantValue(Context $context, Variable $src): Variable
    {
        if ($src->is(Variable::TYPE_INDIRECT) || $src->is(Variable::TYPE_PROPERTY_HOOK_REF)) {
            $out = new Variable();
            $out->copyFrom($src);

            return $out;
        }
        $src = $src->resolveIndirect();
        if ($src->is(Variable::TYPE_OBJECT)) {
            $object = $src->toObject();
            if (null !== $object->closureState) {
                // Closures are live callables — must not immortalize/detach (#17723, zend_closures.c).
                $out = new Variable();
                $out->copyFrom($src);

                return $out;
            }
            if (SplDualIteratorStorage::hasStateFor($object)) {
                // SPL iterator wrappers keep sidecar state keyed by object id (#17721).
                $out = new Variable();
                $out->copyFrom($src);

                return $out;
            }
        }
        if ($src->is(Variable::TYPE_ARRAY)) {
            if (self::arrayContainsRuntimeRefs($src)) {
                $out = new Variable();
                $out->copyFrom($src);

                return $out;
            }

            return ClassConstMaterializer::detachConstantValue(
                self::materializeConstantArrayDeep($context, $src)
            );
        }
        if (self::isEnumCaseVariable($src)) {
            $enumClass = self::enumClassForCaseVariable($src);
            $caseName = self::enumCaseNameForVariable($src);
            if (null !== $enumClass && '' !== $caseName) {
                $runtime = EnumSupport::resolveRuntimeEnumClass($context, $enumClass);
                $canonical = BackedEnum::canonicalCaseVariable($runtime, $caseName);
                if (null !== $canonical && self::isEnumCaseVariable($canonical)) {
                    return ClassConstMaterializer::detachConstantValue($canonical);
                }
            }

            return ClassConstMaterializer::detachConstantValue($src);
        }
        if (!$src->is(Variable::TYPE_INTEGER) && !$src->is(Variable::TYPE_STRING)) {
            return ClassConstMaterializer::detachConstantValue($src);
        }
        foreach ($context->classes as $entry) {
            if (!$entry->isEnum || null === $entry->backedType) {
                continue;
            }
            if (!self::scalarIsLegacyEnumCaseForClass($src, $entry)) {
                continue;
            }
            $match = BackedEnum::tryCaseForValue($entry, $src);
            if (null === $match) {
                continue;
            }
            $canonical = BackedEnum::canonicalCaseVariable($entry, $match->caseName);
            if (null !== $canonical && self::isEnumCaseVariable($canonical)) {
                return ClassConstMaterializer::detachConstantValue($canonical);
            }

            return ClassConstMaterializer::detachConstantValue(
                self::createCase($entry, $match->caseName, $match->backingValue)
            );
        }

        return ClassConstMaterializer::detachConstantValue($src);
    }

    /** True when array storage carries live reference cells (must not immortalize for globals, #6426). */
    public static function arrayContainsRuntimeRefs(Variable $arrayVar): bool
    {
        $arrayVar = $arrayVar->resolveIndirect();
        if (!$arrayVar->is(Variable::TYPE_ARRAY)) {
            return false;
        }

        foreach ($arrayVar->toArray()->iterate(false) as $element) {
            if (
                $element->is(Variable::TYPE_INDIRECT)
                || $element->is(Variable::TYPE_PROPERTY_HOOK_REF)
            ) {
                return true;
            }
            $resolved = $element->resolveIndirect();
            if ($resolved->is(Variable::TYPE_PROPERTY_HOOK_REF)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Class const array literals may contain backing scalars from compile-time fold (#5901, zend_constants.c).
     */
    private static function materializeConstantArrayDeep(Context $context, Variable $src): Variable
    {
        $newHt = new HashTable();
        foreach ($src->toArray()->iterateKeyed(true) as [$key, $value]) {
            $matValue = self::materializeConstantValue($context, $value);
            if ($key->is(Variable::TYPE_INTEGER)) {
                $newHt->addIndex($key->toInt(), $matValue);
            } else {
                $newHt->add($key->toString(), $matValue);
            }
        }
        $out = new Variable(Variable::TYPE_ARRAY);
        $out->array($newHt);

        return $out;
    }

    /**
     * Zend {@see zend_clone_obj} rejection for enum cases (#3554, #5535).
     *
     * When enum constants were materialized as backing scalars (#5514 regression),
     * clone still must throw catchable Error, not LogicException.
     */
    public static function uncloneableEnumClassForClone(Variable $src, ?Context $context = null): ?string
    {
        $src = $src->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $src->type) {
            return $src->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $src->type && self::isEnumCase($src->toObject())) {
            return $src->toObject()->class->name;
        }
        if (null === $context) {
            return null;
        }
        if (!$src->is(Variable::TYPE_INTEGER) && !$src->is(Variable::TYPE_STRING)) {
            return null;
        }
        foreach ($context->classes as $entry) {
            if (!$entry->isEnum || null === $entry->backedType) {
                continue;
            }
            $match = BackedEnum::tryCaseForValue($entry, $src);
            if (null === $match) {
                continue;
            }
            $caseKey = \PHPCompiler\ClassConstName::key($match->caseName);
            if (!isset($entry->constants[$caseKey])) {
                continue;
            }
            $stored = $entry->constants[$caseKey]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $stored->type && self::isEnumCase($stored->toObject())) {
                continue;
            }
            if ($stored->is(Variable::TYPE_INTEGER) || $stored->is(Variable::TYPE_STRING)) {
                return $entry->name;
            }
        }

        return null;
    }

    /**
     * pack() numeric operand — E_WARNING + backing scalar (#5713, #6213, ext/standard/pack.c).
     *
     * @return int|null backing int when $value is an enum case, or null otherwise
     */
    public static function packCoerceToLong(Variable $value, ?Context $context = null, ?Frame $frame = null): ?int
    {
        $entry = self::enumCaseEntryForVariable($value);
        if (null === $entry) {
            return null;
        }
        self::emitScalarCastWarning($context, $frame, $entry->enumClass->name, 'int');
        if (null === $entry->enumClass->backedType) {
            return 1;
        }
        $backing = $entry->backingValue->resolveIndirect();

        return match ($backing->type) {
            Variable::TYPE_INTEGER => $backing->toInt(),
            Variable::TYPE_FLOAT => (int) $backing->toFloat(),
            Variable::TYPE_STRING => (int) $backing->toString(),
            default => 0,
        };
    }

    /**
     * pack() float operand — E_WARNING + backing scalar (#5713, ext/standard/pack.c).
     *
     * @return float|null backing float when $value is an enum case, or null otherwise
     */
    public static function packCoerceToDouble(Variable $value, ?Context $context = null, ?Frame $frame = null): ?float
    {
        $entry = self::enumCaseEntryForVariable($value);
        if (null === $entry) {
            return null;
        }
        self::emitScalarCastWarning($context, $frame, $entry->enumClass->name, 'float');
        if (null === $entry->enumClass->backedType) {
            return 1.0;
        }
        $backing = $entry->backingValue->resolveIndirect();

        return match ($backing->type) {
            Variable::TYPE_FLOAT => $backing->toFloat(),
            Variable::TYPE_INTEGER => (float) $backing->toInt(),
            Variable::TYPE_STRING => (float) $backing->toString(),
            default => 0.0,
        };
    }

    /**
     * pack() string operand — enum cases Error like Zend object-to-string (#5713, ext/standard/pack.c).
     */
    public static function packRejectStringOperand(Variable $value): void
    {
        $entry = self::enumCaseEntryForVariable($value);
        if (null === $entry) {
            return;
        }
        throw new \Error("Object of class {$entry->enumClass->name} could not be converted to string");
    }

    /**
     * Zend scalar (int) cast on enum case operands — E_WARNING + legacy object cast 1 (#5714, zend_operators.c).
     *
     * @return int|null 1 when $value is an enum case, or null otherwise
     */
    public static function tryCastToInt(Variable $value, ?Context $context = null, ?Frame $frame = null): ?int
    {
        $enumClass = self::enumClassForCaseVariable($value);
        if (null === $enumClass) {
            return null;
        }
        self::emitScalarCastWarning($context, $frame, $enumClass->name, 'int');

        return 1;
    }

    /**
     * Zend scalar (float) cast on enum case operands — E_WARNING + 1.0 (#5714, #5623, zend_operators.c).
     *
     * @return float|null 1.0 when $value is an enum case, or null otherwise
     */
    public static function tryCastToFloat(Variable $value, ?Context $context = null, ?Frame $frame = null): ?float
    {
        $enumClass = self::enumClassForCaseVariable($value);
        if (null === $enumClass) {
            return null;
        }
        self::emitScalarCastWarning($context, $frame, $enumClass->name, 'float');

        return 1.0;
    }

    private static function emitScalarCastWarning(
        ?Context $context,
        ?Frame $frame,
        string $className,
        string $kind
    ): void {
        if (null === $context) {
            return;
        }
        $message = 'int' === $kind
            ? "Object of class {$className} could not be converted to int"
            : "Object of class {$className} could not be converted to float";
        $context->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null !== $frame && '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
            $context,
            $frame
        );
    }
}
