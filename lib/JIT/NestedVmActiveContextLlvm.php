<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\VmActiveContextJitHelper} (#17391).
 */
final class NestedVmActiveContextLlvm
{
    private const PROXY = 'phpcompiler\\vm\\vmactivecontextjithelper::resolve';

    public static function ensureMethod(Context $context): bool
    {
        if ($context->functionIsRegistered(self::PROXY)) {
            return true;
        }
        VmActiveContextLlvm::ensureAbi($context);
        $context->functionProxies[self::PROXY] = new Call\VmActiveContextResolve();

        return true;
    }

    public static function isNestedMethod(string $methodLc): bool
    {
        return 'resolve' === $methodLc;
    }
}
