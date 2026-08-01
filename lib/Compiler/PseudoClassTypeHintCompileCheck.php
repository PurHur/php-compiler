<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Reject self/parent/static type hints outside an active class scope (#17480).
 *
 * php-src: Zend/zend_compile.c — zend_compile_type_holder() / return & param validation
 */
final class PseudoClassTypeHintCompileCheck
{
    public static function messageFor(string $keyword): string
    {
        return sprintf('Cannot use "%s" when no class scope is active', strtolower($keyword));
    }

    public static function findKeyword(?Op\Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof Op\Type\Nullable) {
            return self::findKeyword($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                $keyword = self::findKeyword($member);
                if (null !== $keyword) {
                    return $keyword;
                }
            }

            return null;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                $keyword = self::findKeyword($member);
                if (null !== $keyword) {
                    return $keyword;
                }
            }

            return null;
        }
        if ($type instanceof Op\Type\Literal) {
            return self::pseudoClassKeywordFromName($type->name);
        }
        if ($type instanceof Op\Type\Reference) {
            return self::pseudoClassKeywordFromOperand($type->declaration);
        }

        return null;
    }

    /** True when $keyword appears as a type atom in any union/intersection/nullable arm (#26540). */
    public static function containsKeyword(?Op\Type $type, string $keyword): bool
    {
        if (null === $type) {
            return false;
        }
        $want = strtolower($keyword);
        if ($type instanceof Op\Type\Nullable) {
            return self::containsKeyword($type->subtype, $want);
        }
        if ($type instanceof Op\Type\Union_ || $type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if (self::containsKeyword($member, $want)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Literal) {
            return $want === self::pseudoClassKeywordFromName($type->name);
        }
        if ($type instanceof Op\Type\Reference) {
            return $want === self::pseudoClassKeywordFromOperand($type->declaration);
        }

        return false;
    }

    private static function pseudoClassKeywordFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return self::pseudoClassKeywordFromName($op->value);
        }
        if ($op instanceof Operand\Variable) {
            return self::pseudoClassKeywordFromOperand($op->name);
        }

        return null;
    }

    private static function pseudoClassKeywordFromName(string $name): ?string
    {
        $lc = strtolower(ltrim($name, '\\'));
        if (in_array($lc, ['self', 'parent', 'static'], true)) {
            return $lc;
        }

        return null;
    }
}
