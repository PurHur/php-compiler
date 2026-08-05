<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;

/** Lazy registration for ext/simplexml user-script AOT proxies (#19306, #26863). */
final class SimpleXmlInstanceMethodJit
{
    /** @var array<string, true> */
    private const METHODS = [
        'simplexmlelement::__construct' => true,
        'simplexmlelement::addchild' => true,
        'simplexmlelement::asxml' => true,
        'simplexmlelement::savexml' => true,
        'simplexmlelement::xpath' => true,
        'simplexmlelement::registerxpathnamespace' => true,
        'simplexmlelement::__get' => true,
        'simplexmlelement::offsetget' => true,
        'simplexmlelement::count' => true,
        'simplexmlelement::__tostring' => true,
    ];

    public static function isSimpleXmlInstanceMethodProxy(string $proxyName): bool
    {
        $lc = strtolower(ltrim($proxyName, '\\'));

        return isset(self::METHODS[$lc]);
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
        if ('simplexmlelement::__construct' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementConstruct();

            return;
        }
        if ('simplexmlelement::addchild' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementAddChild();

            return;
        }
        // saveXML is a php-src FALIAS of asXML (#19413).
        if ('simplexmlelement::asxml' === $lc || 'simplexmlelement::savexml' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementAsXml();

            return;
        }
        if ('simplexmlelement::xpath' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementXpath();

            return;
        }
        if ('simplexmlelement::registerxpathnamespace' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementRegisterXPathNamespace();

            return;
        }
        if ('simplexmlelement::__get' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementGet();

            return;
        }
        if ('simplexmlelement::offsetget' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementOffsetGet();

            return;
        }
        if ('simplexmlelement::count' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementCount();

            return;
        }
        if ('simplexmlelement::__tostring' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementToString();
        }
    }
}
