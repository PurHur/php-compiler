<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\ObjectEntry} instance helpers (#19048).
 */
final class NestedVmObjectMethodLlvm
{
    /** @var array<string, class-string<Call>> */
    private const METHOD_HANDLERS = [
        'comparespaceship' => Call\ObjectCompareSpaceship::class,
    ];

    public static function ensureMethod(Context $context, string $methodLc): bool
    {
        $handler = self::METHOD_HANDLERS[$methodLc] ?? null;
        if (null === $handler) {
            return false;
        }
        $proxyName = 'phpcompiler\\vm\\objectentry::'.$methodLc;
        if ($context->functionIsRegistered($proxyName)) {
            return true;
        }
        $context->functionProxies[$proxyName] = new $handler();

        return true;
    }

    public static function isNestedObjectMethod(string $methodLc): bool
    {
        return isset(self::METHOD_HANDLERS[$methodLc]);
    }
}
