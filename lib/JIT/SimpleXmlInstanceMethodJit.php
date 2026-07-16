<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;

/** Lazy registration for ext/simplexml user-script AOT proxies (#19306). */
final class SimpleXmlInstanceMethodJit
{
    /** @var array<string, true> */
    private const METHODS = [
        'simplexmlelement::__construct' => true,
        'simplexmlelement::addchild' => true,
        'simplexmlelement::asxml' => true,
    ];

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
        if ('simplexmlelement::__construct' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementConstruct();

            return;
        }
        if ('simplexmlelement::addchild' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementAddChild();

            return;
        }
        if ('simplexmlelement::asxml' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementAsXml();
        }
    }
}
