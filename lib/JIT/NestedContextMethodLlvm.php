<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\Context} instance helpers (#13245).
 */
final class NestedContextMethodLlvm
{
    private const PROXY_RUN_STACK_FRAMES = 'phpcompiler\\vm\\context::runstackframes';

    public static function ensureMethod(Context $context, string $methodLc): bool
    {
        if ('runstackframes' !== $methodLc) {
            return false;
        }
        if ($context->functionIsRegistered(self::PROXY_RUN_STACK_FRAMES)) {
            return true;
        }
        $context->functionProxies[self::PROXY_RUN_STACK_FRAMES] = new Call\ContextRunStackFramesNested();

        return true;
    }

    public static function isNestedContextMethod(string $methodLc): bool
    {
        return 'runstackframes' === $methodLc;
    }
}
