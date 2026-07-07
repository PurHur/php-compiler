<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\DomInstanceMethod;

/** Lazy registration for ext/dom JIT instance-method proxies (#17130). */
final class DomInstanceMethodJit
{
    public static function isDomInstanceMethodProxy(string $proxyName): bool
    {
        $lc = strtolower(ltrim($proxyName, '\\'));

        return (bool) preg_match('/^dom[a-z0-9_]*::[a-z0-9_]+$/', $lc);
    }

    public static function ensureProxy(Context $context, string $proxyName): void
    {
        $lc = strtolower(ltrim($proxyName, '\\'));
        if (isset($context->functionProxies[$lc])
            && !($context->functionProxies[$lc] instanceof Call\ExternalMethod)) {
            return;
        }
        if (!self::isDomInstanceMethodProxy($lc)) {
            return;
        }
        if (!preg_match('/^(dom[a-z0-9_]*)::([a-z0-9_]+)$/', $lc, $matches)) {
            return;
        }
        $context->functionProxies[$lc] = new DomInstanceMethod($matches[1], $matches[2]);
    }

    /** Register domdocument::createelement without generic nested helper (#17130). */
    public static function registerKnownProxies(Context $context): void
    {
        foreach (self::KNOWN_METHODS as $classLc => $methods) {
            foreach ($methods as $methodLc) {
                self::ensureProxy($context, $classLc.'::'.$methodLc);
            }
        }
    }

    /** @var array<string, list<string>> */
    private const KNOWN_METHODS = [
        'domdocument' => ['createelement', 'appendchild'],
        'domnode' => ['appendchild'],
        'domelement' => ['setattribute'],
        'domtokenlist' => ['add', 'contains', 'item', 'toggle', 'remove'],
    ];
}
