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
 * Zend zend_compile_type — redundant true|false union must use bool (#12045, #17996).
 *
 * php-src validates at runtime when the function/class member is registered, not at compile time.
 */
final class RedundantTrueFalseUnionCheck
{
    public const FATAL_MESSAGE = 'Type contains both true and false, bool should be used instead';

    public static function assertNotRedundant(?Type $type, Frame $frame, ?SourceLocation $sourceLocation = null): void
    {
        if (!self::isRedundantTrueFalseUnion($type)) {
            return;
        }
        self::throwFatal($frame, $sourceLocation);
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
     * @return never
     */
    private static function throwFatal(Frame $frame, ?SourceLocation $sourceLocation): void
    {
        $file = '' !== $frame->scriptPath ? $frame->scriptPath : 'Standard input code';
        if (null !== $sourceLocation && '' !== $sourceLocation->filename) {
            $file = $sourceLocation->filename;
        }
        $line = null !== $sourceLocation && $sourceLocation->startLine > 0
            ? $sourceLocation->startLine
            : 0;
        throw new \LogicException(sprintf(
            'Fatal error: %s in %s on line %d',
            self::FATAL_MESSAGE,
            $file,
            $line
        ));
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
