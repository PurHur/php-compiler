<?php

declare(strict_types=1);

namespace PHPCompiler\VM;


use PHPCompiler\Config;

/**
 * Fiber call-stack guard (issue #7267; php-src Zend/zend_execute.c zend_call_stack_size_error).
 *
 * VM fibers use an isolated run stack; depth is tracked in frames because we do not
 * allocate native fiber stacks with guard pages.
 */
final class FiberStackLimit
{
    /** php-src default fiber.stack_size (1 MiB). */
    public const DEFAULT_MAX_BYTES = 1 << 20;

    /** Rough bytes per VM frame for message parity with zend.max_allowed_stack_size checks. */
    private const ESTIMATED_BYTES_PER_FRAME = 4096;

    public static function maxStackBytes(): int
    {
        $raw = Config::getenv('PHP_COMPILER_FIBER_MAX_STACK_BYTES');
        if (is_string($raw) && '' !== $raw && is_numeric($raw)) {
            return max(1, (int) $raw);
        }

        return self::DEFAULT_MAX_BYTES;
    }

    public static function maxStackFrames(): int
    {
        $raw = Config::getenv('PHP_COMPILER_FIBER_MAX_STACK_FRAMES');
        if (is_string($raw) && '' !== $raw && is_numeric($raw)) {
            return max(1, (int) $raw);
        }

        return max(64, (int) (self::maxStackBytes() / self::ESTIMATED_BYTES_PER_FRAME));
    }

    public static function currentDepth(Context $ctx): int
    {
        return \count($ctx->runStackFrames()) + 1;
    }

    public static function wouldOverflow(Context $ctx): bool
    {
        return self::currentDepth($ctx) >= self::maxStackFrames();
    }

    public static function stackSizeErrorMessage(): string
    {
        return \sprintf(
            'Maximum call stack size of %d bytes (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?',
            self::maxStackBytes()
        );
    }
}
