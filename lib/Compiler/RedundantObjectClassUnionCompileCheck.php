<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op\Type;
use PHPCfg\Op\Type\Intersection;
use PHPCfg\Op\Type\Literal;
use PHPCfg\Op\Type\Nullable;
use PHPCfg\Op\Type\Reference;
use PHPCfg\Op\Type\Union_;

/**
 * Zend zend_compile_type — {@code object} + class/interface/static is redundant (#26563).
 *
 * php-src: Zend/zend_compile.c — after union assembly, reject MAY_BE_OBJECT together with
 * a complex class list (unless the only class came from iterable→Traversable fallback) or
 * MAY_BE_STATIC:
 * {@code Type A|object contains both object and a class type, which is redundant}.
 *
 * {@code object|iterable} stays valid (Traversable is only the iterable alias). Explicit
 * {@code object|Traversable} / {@code object|SomeClass} / {@code object|(A&B)} fatals.
 *
 * @see DuplicateUnionMemberCompileCheck for exact duplicate arms (#26556)
 * @see RedundantTrueFalseUnionCheck for {@code bool|true} (#26555)
 */
final class RedundantObjectClassUnionCompileCheck
{
    public static function messageFor(string $typeLabel): string
    {
        return sprintf(
            'Type %s contains both object and a class type, which is redundant',
            $typeLabel
        );
    }

    /**
     * True when a union pairs {@code object} with a real class/interface/static arm.
     * Does not expand {@code iterable} — matching zend {@code has_only_iterable_class}.
     */
    public static function isRedundant(?Type $type): bool
    {
        if (null === $type) {
            return false;
        }

        $hasObject = false;
        $hasClassType = false;
        $hasStatic = false;
        self::scan($type, $hasObject, $hasClassType, $hasStatic);

        return $hasObject && ($hasClassType || $hasStatic);
    }

    /**
     * @param-out bool $hasObject
     * @param-out bool $hasClassType
     * @param-out bool $hasStatic
     */
    private static function scan(
        Type $type,
        bool &$hasObject,
        bool &$hasClassType,
        bool &$hasStatic
    ): void {
        if ($type instanceof Nullable) {
            self::scan($type->subtype, $hasObject, $hasClassType, $hasStatic);

            return;
        }
        if ($type instanceof Union_) {
            foreach ($type->types as $member) {
                self::scan($member, $hasObject, $hasClassType, $hasStatic);
            }

            return;
        }
        if ($type instanceof Intersection) {
            // DNF intersection arm is always a class-type list member.
            $hasClassType = true;

            return;
        }
        if ($type instanceof Reference) {
            // Any named class/interface (including Traversable, self, parent).
            $hasClassType = true;

            return;
        }
        if ($type instanceof Literal) {
            $lc = strtolower(ltrim($type->name, '\\'));
            if ('object' === $lc) {
                $hasObject = true;

                return;
            }
            if ('static' === $lc) {
                $hasStatic = true;

                return;
            }
            if ('self' === $lc || 'parent' === $lc) {
                $hasClassType = true;

                return;
            }
            // iterable / scalars / true|false / array — not a conflicting class arm.
            return;
        }
    }
}
