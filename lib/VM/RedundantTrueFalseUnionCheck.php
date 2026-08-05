<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Op\Type;
use PHPCfg\Op\Type\Intersection;
use PHPCfg\Op\Type\Literal;
use PHPCfg\Op\Type\Nullable;
use PHPCfg\Op\Type\Union_;
use PHPCompiler\Block;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;

/**
 * Zend zend_compile_type — redundant true/false/bool union members (#12045, #17996, #26555).
 *
 * php-src validates when the function/class member is registered (VM FUNCDEF / property
 * declare); JIT CLI hits the same path via runtime->run after emit.
 */
final class RedundantTrueFalseUnionCheck
{
    public const FATAL_MESSAGE = 'Type contains both true and false, bool should be used instead';

    public const DUPLICATE_TRUE_MESSAGE = 'Duplicate type true is redundant';

    public const DUPLICATE_FALSE_MESSAGE = 'Duplicate type false is redundant';

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

    public static function isRedundantTrueFalseUnion(?Type $type): bool
    {
        if (null === $type) {
            return false;
        }

        return self::containsLiteralBool($type, 'true')
            && self::containsLiteralBool($type, 'false');
    }

    /**
     * Zend-shaped fatal message for a redundant true/false/bool union, or null if valid.
     * true|false wins over bool|true (matches zend_compile_type for true|false|bool).
     */
    public static function redundantMessage(?Type $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if (self::isRedundantTrueFalseUnion($type)) {
            return self::FATAL_MESSAGE;
        }
        if (self::containsBuiltinBool($type) && self::containsLiteralBool($type, 'true')) {
            return self::DUPLICATE_TRUE_MESSAGE;
        }
        if (self::containsBuiltinBool($type) && self::containsLiteralBool($type, 'false')) {
            return self::DUPLICATE_FALSE_MESSAGE;
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

    private static function containsBuiltinBool(?Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Literal) {
            $name = strtolower($type->name);

            return 'bool' === $name || 'boolean' === $name;
        }
        if ($type instanceof Union_) {
            foreach ($type->types as $member) {
                if (self::containsBuiltinBool($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Intersection) {
            foreach ($type->types as $member) {
                if (self::containsBuiltinBool($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Nullable) {
            return self::containsBuiltinBool($type->subtype);
        }

        return false;
    }

    private static function containsLiteralBool(?Type $type, string $name): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Literal && $name === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Union_) {
            foreach ($type->types as $member) {
                if (self::containsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Intersection) {
            foreach ($type->types as $member) {
                if (self::containsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Nullable) {
            return self::containsLiteralBool($type->subtype, $name);
        }

        return false;
    }
}
