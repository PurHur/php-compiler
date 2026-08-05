<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Op\Type;
use PHPCfg\Op\Type\Intersection;
use PHPCfg\Op\Type\Literal;
use PHPCfg\Op\Type\Nullable;
use PHPCfg\Op\Type\Reference;
use PHPCfg\Op\Type\Union_;
use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;

/**
 * Zend zend_compile_type — iterable expands to Traversable|array (#26564, #26591).
 *
 * php-src: Zend/zend_compile.c zend_compile_single_typename (IS_ITERABLE →
 * Traversable + MAY_BE_ARRAY) then left-to-right union mask/class overlap:
 * {@code Duplicate type array is redundant} / {@code Duplicate type Traversable is redundant}.
 *
 * Checked at FUNCDEF / property declare (same path as RedundantTrueFalseUnionCheck).
 */
final class RedundantIterableUnionCheck
{
    public const DUPLICATE_ARRAY_MESSAGE = 'Duplicate type array is redundant';

    public const DUPLICATE_TRAVERSABLE_MESSAGE = 'Duplicate type Traversable is redundant';

    public static function assertNotRedundant(?Type $type, Frame $frame, ?SourceLocation $sourceLocation = null): void
    {
        $message = self::redundantMessage($type);
        if (null === $message) {
            return;
        }
        self::throwFatal($frame, $sourceLocation, $message);
    }

    public static function assertFunctionBlock(Block $block, Frame $frame, ?SourceLocation $sourceLocation = null): void
    {
        if (null !== $block->returnDeclaredType) {
            self::assertNotRedundant($block->returnDeclaredType, $frame, $sourceLocation);
        }
        foreach ($block->paramDeclaredTypes as $declared) {
            self::assertNotRedundant($declared, $frame, $sourceLocation);
        }
    }

    public static function assertPropertyOp(Frame $frame, OpCode $op): void
    {
        self::assertNotRedundant($op->cfgDeclaredType, $frame, $op->sourceLocation);
    }

    /**
     * Zend-shaped fatal message for iterable⊇array / iterable⊇Traversable redundancy, or null.
     */
    public static function redundantMessage(?Type $type): ?string
    {
        if (null === $type) {
            return null;
        }

        $seenArray = false;
        $seenTraversable = false;

        return self::scan($type, $seenArray, $seenTraversable);
    }

    /**
     * Left-to-right scan matching zend_compile_typename union handling.
     * When adding iterable, array-mask overlap is reported before Traversable class overlap.
     *
     * @param-out bool $seenArray
     * @param-out bool $seenTraversable
     */
    private static function scan(Type $type, bool &$seenArray, bool &$seenTraversable): ?string
    {
        if ($type instanceof Nullable) {
            return self::scan($type->subtype, $seenArray, $seenTraversable);
        }
        if ($type instanceof Union_) {
            foreach ($type->types as $member) {
                $message = self::scan($member, $seenArray, $seenTraversable);
                if (null !== $message) {
                    return $message;
                }
            }

            return null;
        }
        // Intersection arms are not expanded for this rule (Zend uses a different
        // "more restrictive than Traversable" message for Traversable&X|iterable).
        if ($type instanceof Intersection) {
            return null;
        }

        $kind = self::memberKind($type);
        if (null === $kind) {
            return null;
        }

        if ('iterable' === $kind) {
            // zend_compile_single_typename: iterable → Traversable + MAY_BE_ARRAY;
            // mask overlap is checked before class-list duplicate.
            if ($seenArray) {
                return self::DUPLICATE_ARRAY_MESSAGE;
            }
            if ($seenTraversable) {
                return self::DUPLICATE_TRAVERSABLE_MESSAGE;
            }
            $seenArray = true;
            $seenTraversable = true;

            return null;
        }
        if ('array' === $kind) {
            if ($seenArray) {
                return self::DUPLICATE_ARRAY_MESSAGE;
            }
            $seenArray = true;

            return null;
        }
        if ('traversable' === $kind) {
            if ($seenTraversable) {
                return self::DUPLICATE_TRAVERSABLE_MESSAGE;
            }
            $seenTraversable = true;

            return null;
        }

        return null;
    }

    private static function memberKind(Type $type): ?string
    {
        if ($type instanceof Literal) {
            $lc = strtolower(ltrim($type->name, '\\'));

            return match ($lc) {
                'iterable' => 'iterable',
                'array' => 'array',
                'traversable' => 'traversable',
                default => null,
            };
        }
        if ($type instanceof Reference) {
            $name = self::referenceName($type);
            if (null === $name) {
                return null;
            }
            if ('traversable' === strtolower(ltrim($name, '\\'))) {
                return 'traversable';
            }
        }

        return null;
    }

    private static function referenceName(Reference $type): ?string
    {
        $op = $type->declaration;
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            $name = $op->name;
            if ($name instanceof Operand\Literal && is_string($name->value)) {
                return $name->value;
            }
        }

        return null;
    }

    /**
     * @return never
     */
    private static function throwFatal(Frame $frame, ?SourceLocation $sourceLocation, string $message): void
    {
        $file = '' !== $frame->scriptPath ? $frame->scriptPath : 'Standard input code';
        if (null !== $sourceLocation && '' !== $sourceLocation->filename) {
            $file = $sourceLocation->filename;
        }
        $line = null !== $sourceLocation && $sourceLocation->startLine > 0
            ? $sourceLocation->startLine
            : 0;
        throw new \LogicException(sprintf(
            'PHP Fatal error:  %s in %s on line %d',
            $message,
            $file,
            $line
        ));
    }
}
