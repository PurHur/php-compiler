<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;

/** Lazy registration for Randomizer user-script AOT proxies (#19574). */
final class RandomizerInstanceMethodJit
{
    /** @var array<string, true> */
    private const METHODS = [
        'random\\engine\\mt19937::__construct' => true,
        'random\\randomizer::__construct' => true,
        'random\\randomizer::getbytesfromstring' => true,
    ];

    public static function isRandomizerInstanceMethodProxy(string $proxyName): bool
    {
        $lc = strtolower(ltrim($proxyName, '\\'));

        return isset(self::METHODS[$lc]);
    }

    public static function isUserScriptAot(): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $userScript || 'true' === strtolower((string) $userScript);
    }

    public static function ensureProxy(Context $context, string $proxyName): void
    {
        $lc = strtolower(ltrim($proxyName, '\\'));
        if (!isset(self::METHODS[$lc])) {
            return;
        }
        if (isset($context->functionProxies[$lc])
            && !($context->functionProxies[$lc] instanceof Call\ExternalMethod)) {
            return;
        }
        if ('random\\engine\\mt19937::__construct' === $lc) {
            $context->functionProxies[$lc] = new Call\RandomizerMt19937Construct();

            return;
        }
        if ('random\\randomizer::__construct' === $lc) {
            $context->functionProxies[$lc] = new Call\RandomizerConstruct();

            return;
        }
        if ('random\\randomizer::getbytesfromstring' === $lc) {
            $context->functionProxies[$lc] = new Call\RandomizerGetBytesFromString();
        }
    }
}
