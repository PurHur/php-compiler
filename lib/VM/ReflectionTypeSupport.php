<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\DnfType;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Op\Type as CfgType;

/**
 * Build ReflectionNamedType / ReflectionUnionType / ReflectionIntersectionType (#3355).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_type_*
 */
final class ReflectionTypeSupport
{
    /** @var list<string> */
    private const BUILTIN_NAMES = [
        'int', 'integer', 'float', 'double', 'bool', 'boolean', 'true', 'false',
        'string', 'array', 'callable', 'iterable', 'object', 'null', 'mixed', 'never', 'void',
    ];

    public static function buildTypeVariable(Context $ctx, CfgType $type): Variable
    {
        $obj = self::buildTypeObject($ctx, $type);
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object($obj);

        return $out;
    }

    public static function buildTypeObject(Context $ctx, CfgType $type): ObjectEntry
    {
        if ($type instanceof CfgType\Nullable) {
            $subtype = $type->subtype;
            if (
                $subtype instanceof CfgType\Literal
                || $subtype instanceof CfgType\Reference
                || $subtype instanceof CfgType\Mixed_
            ) {
                $inner = self::cfgTypeString($subtype);

                return self::buildNamedObject($ctx, $subtype, '?'.$inner, true);
            }

            return self::buildUnionObject(
                $ctx,
                [$subtype, new CfgType\Literal('null')],
                self::cfgTypeStringForDump($type)
            );
        }
        if ($type instanceof CfgType\Union_) {
            // ReflectionType::__toString — ?T for T|null; DNF parens via cfgTypeString (#23065).
            return self::buildUnionObject($ctx, $type->types, self::cfgTypeStringForDump($type));
        }
        if ($type instanceof CfgType\Intersection) {
            return self::buildIntersectionObject($ctx, $type->types, self::cfgTypeString($type));
        }

        return self::buildNamedObject($ctx, $type, self::cfgTypeString($type));
    }

    public static function tryObjectTypeString(ObjectEntry $object): ?string
    {
        $classLc = strtolower($object->class->name);
        if (!self::isReflectionTypeClass($classLc)) {
            return null;
        }
        $stored = $object->getProperty(ReflectionSupport::PROP_TYPE_STRING)->resolveIndirect();
        if (Variable::TYPE_STRING !== $stored->type) {
            return null;
        }

        return $stored->toString();
    }

    public static function isReflectionTypeClass(string $classLc): bool
    {
        return in_array($classLc, [
            ReflectionSupport::REFLECTION_NAMED_TYPE,
            ReflectionSupport::REFLECTION_UNION_TYPE,
            ReflectionSupport::REFLECTION_INTERSECTION_TYPE,
        ], true);
    }

    public static function cfgTypeString(CfgType $type): string
    {
        if ($type instanceof CfgType\Literal) {
            return $type->name;
        }
        if ($type instanceof CfgType\Nullable) {
            // DNF: intersection inside nullable / |null needs parens (php-src zend_type_to_string).
            return self::formatUnionMember($type->subtype).'|null';
        }
        if ($type instanceof CfgType\Union_) {
            $parts = [];
            // php-src zend_type_to_string_resolved — list types then MAY_BE_* order (#23487).
            foreach (self::sortUnionMembersLikeZend($type->types) as $member) {
                // DNF parentheses around intersection groups in a union (#23065).
                $parts[] = self::formatUnionMember($member);
            }

            return implode('|', $parts);
        }
        if ($type instanceof CfgType\Intersection) {
            $parts = [];
            foreach ($type->types as $member) {
                $parts[] = self::cfgTypeString($member);
            }

            return implode('&', $parts);
        }
        if ($type instanceof CfgType\Reference) {
            return self::referenceTypeName($type);
        }
        if ($type instanceof CfgType\Mixed_) {
            return 'mixed';
        }
        if ($type instanceof CfgType\Never_) {
            return 'never';
        }
        if ($type instanceof CfgType\Void_) {
            return 'void';
        }

        throw new \LogicException('Unsupported declared type for reflection: '.get_class($type));
    }

    /**
     * Zend _function_string / _parameter_string / ReflectionType::__toString (#22522, #23065).
     *
     * Simple nullables and T|null unions render as "?T" (php-src zend_type pretty-print).
     * Multi-member unions keep DNF parentheses from {@see cfgTypeString()}.
     */
    public static function cfgTypeStringForDump(CfgType $type): string
    {
        if ($type instanceof CfgType\Nullable) {
            $subtype = $type->subtype;
            if (
                $subtype instanceof CfgType\Literal
                || $subtype instanceof CfgType\Reference
                || $subtype instanceof CfgType\Mixed_
            ) {
                return '?'.self::cfgTypeString($subtype);
            }

            return self::cfgTypeString($type);
        }
        if ($type instanceof CfgType\Union_) {
            $nonNull = [];
            $sawNull = false;
            foreach ($type->types as $member) {
                if (
                    ($member instanceof CfgType\Literal && 'null' === strtolower($member->name))
                    || $member instanceof CfgType\Nullable
                ) {
                    if ($member instanceof CfgType\Nullable) {
                        $nonNull[] = $member->subtype;
                    }
                    $sawNull = true;
                    continue;
                }
                $nonNull[] = $member;
            }
            if ($sawNull && 1 === \count($nonNull)) {
                $only = $nonNull[0];
                if (
                    $only instanceof CfgType\Literal
                    || $only instanceof CfgType\Reference
                    || $only instanceof CfgType\Mixed_
                ) {
                    return '?'.self::cfgTypeString($only);
                }
            }
        }

        return self::cfgTypeString($type);
    }

    /** Union-member pretty-print: wrap intersections for DNF (php-src zend_type_to_string). */
    private static function formatUnionMember(CfgType $member): string
    {
        $part = self::cfgTypeString($member);
        if ($member instanceof CfgType\Intersection) {
            return '('.$part.')';
        }

        return $part;
    }

    public static function cfgTypeFromLabel(string $label): ?CfgType
    {
        $label = trim($label);
        if ('' === $label) {
            return null;
        }
        if ('mixed' === strtolower($label)) {
            return new CfgType\Mixed_();
        }
        if ('never' === strtolower($label)) {
            return new CfgType\Never_();
        }
        if ('void' === strtolower($label)) {
            return new CfgType\Void_();
        }
        if (str_starts_with($label, '?')) {
            $inner = self::cfgTypeFromLabel(substr($label, 1));

            return null !== $inner ? new CfgType\Nullable($inner) : null;
        }
        if (str_contains($label, '|')) {
            $members = [];
            foreach (explode('|', $label) as $part) {
                $member = self::cfgTypeFromLabel(trim($part));
                if (null !== $member) {
                    $members[] = $member;
                }
            }
            if ([] === $members) {
                return null;
            }
            if (1 === \count($members)) {
                return $members[0];
            }

            return new CfgType\Union_($members);
        }
        if (str_contains($label, '&')) {
            $members = [];
            foreach (explode('&', $label) as $part) {
                $member = self::cfgTypeFromLabel(trim($part));
                if (null !== $member) {
                    $members[] = $member;
                }
            }
            if ([] === $members) {
                return null;
            }
            if (1 === \count($members)) {
                return $members[0];
            }

            return new CfgType\Intersection($members);
        }

        return new CfgType\Literal($label);
    }

    public static function allowsNullFromCfg(CfgType $type): bool
    {
        if ($type instanceof CfgType\Nullable) {
            return true;
        }
        // Explicit `mixed` includes null (php-src ReflectionNamedType::allowsNull).
        // Absent types are Mixed_ and stripped before this helper (#22064 / #22524).
        if ($type instanceof CfgType\Literal) {
            $name = strtolower($type->name);

            return 'null' === $name || 'mixed' === $name;
        }
        if ($type instanceof CfgType\Union_) {
            foreach ($type->types as $member) {
                if (self::allowsNullFromCfg($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof CfgType\Intersection) {
            foreach ($type->types as $member) {
                if (!self::allowsNullFromCfg($member)) {
                    return false;
                }
            }

            return [] !== $type->types;
        }

        return false;
    }

    /**
     * @param list<CfgType> $members
     */
    private static function buildUnionObject(Context $ctx, array $members, string $typeString): ObjectEntry
    {
        $class = self::requireClass($ctx, ReflectionSupport::REFLECTION_UNION_TYPE);
        $obj = new ObjectEntry($class);
        $obj->constructed = true;
        // php-src ReflectionUnionType::allowsNull — true only when null is a member
        // (or a nested nullable/mixed), not for every multi-type union (#22851).
        self::storeCommonTypeProps($obj, $typeString, self::allowsNullFromCfg(
            new CfgType\Union_($members)
        ));
        // getTypes() matches zend_type_to_string / ReflectionUnionType order (#23487).
        $obj->getProperty(ReflectionSupport::PROP_TYPE_MEMBERS)->copyFrom(
            self::membersArray($ctx, self::sortUnionMembersLikeZend($members))
        );

        return $obj;
    }

    /**
     * @param list<CfgType> $members
     * @return list<CfgType>
     */
    private static function sortUnionMembersLikeZend(array $members): array
    {
        if (\count($members) <= 1) {
            return $members;
        }
        $order = DnfType::zendBuiltinUnionOrder();
        $list = [];
        $builtins = [];
        foreach ($members as $member) {
            $rank = self::zendBuiltinUnionMemberRank($member, $order);
            if (null === $rank) {
                $list[] = $member;
            } else {
                $builtins[] = [$rank, $member];
            }
        }
        usort(
            $builtins,
            static fn (array $a, array $b): int => $a[0] <=> $b[0]
        );

        return array_merge($list, array_column($builtins, 1));
    }

    /** @param array<string, int> $order */
    private static function zendBuiltinUnionMemberRank(CfgType $member, array $order): ?int
    {
        if ($member instanceof CfgType\Literal) {
            return $order[strtolower($member->name)] ?? null;
        }
        if ($member instanceof CfgType\Mixed_) {
            // mixed is MAY_BE_ANY — not a multi-member union in php-src.
            return null;
        }

        return null;
    }

    /**
     * @param list<CfgType> $members
     */
    private static function buildIntersectionObject(Context $ctx, array $members, string $typeString): ObjectEntry
    {
        $class = self::requireClass($ctx, ReflectionSupport::REFLECTION_INTERSECTION_TYPE);
        $obj = new ObjectEntry($class);
        $obj->constructed = true;
        self::storeCommonTypeProps($obj, $typeString, self::allowsNullFromCfg(
            new CfgType\Intersection($members)
        ));
        $obj->getProperty(ReflectionSupport::PROP_TYPE_MEMBERS)->copyFrom(
            self::membersArray($ctx, $members)
        );

        return $obj;
    }

    private static function buildNamedObject(
        Context $ctx,
        CfgType $type,
        string $typeString,
        ?bool $allowsNullOverride = null,
    ): ObjectEntry {
        $class = self::requireClass($ctx, ReflectionSupport::REFLECTION_NAMED_TYPE);
        $obj = new ObjectEntry($class);
        $obj->constructed = true;
        $name = self::cfgTypeString($type);
        $allowsNull = $allowsNullOverride ?? self::allowsNullFromCfg($type);
        self::storeCommonTypeProps($obj, $typeString, $allowsNull);
        $obj->getProperty(ReflectionSupport::PROP_TYPE_NAME)->string($name);
        $obj->getProperty(ReflectionSupport::PROP_TYPE_BUILTIN)->bool(self::isBuiltinTypeName($name));
        // Named types have no member list; empty array keeps var_export/get_object_vars off uninitialized slots (#9873).
        $obj->getProperty(ReflectionSupport::PROP_TYPE_MEMBERS)->newArray();

        return $obj;
    }

    private static function storeCommonTypeProps(ObjectEntry $obj, string $typeString, bool $allowsNull): void
    {
        $obj->getProperty(ReflectionSupport::PROP_TYPE_STRING)->string($typeString);
        $obj->getProperty(ReflectionSupport::PROP_TYPE_ALLOWS_NULL)->bool($allowsNull);
    }

    /**
     * @param list<CfgType> $members
     */
    private static function membersArray(Context $ctx, array $members): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($members as $member) {
            $ht->append(self::buildTypeVariable($ctx, $member));
        }

        return $result;
    }

    private static function referenceTypeName(CfgType\Reference $type): string
    {
        $name = self::staticNameFromOperand($type->declaration);
        if (null === $name || '' === $name) {
            throw new \LogicException('Unsupported reference type for reflection');
        }

        return ltrim($name, '\\');
    }

    private static function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return self::staticNameFromOperand($op->name);
        }

        return null;
    }

    private static function isBuiltinTypeName(string $name): bool
    {
        return in_array(strtolower($name), self::BUILTIN_NAMES, true);
    }

    /** Public for ReflectionParameter::getClass() name filtering (#22408). */
    public static function isBuiltinTypeNamePublic(string $name): bool
    {
        return self::isBuiltinTypeName($name);
    }

    private static function requireClass(Context $ctx, string $lcName): ClassEntry
    {
        $entry = $ctx->classes[$lcName] ?? null;
        if (null === $entry) {
            throw new \LogicException("Reflection type class {$lcName} is not registered");
        }

        return $entry;
    }
}
