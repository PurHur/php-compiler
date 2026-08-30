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
        'simplexmlelement::addattribute' => true,
        'simplexmlelement::asxml' => true,
        'simplexmlelement::savexml' => true,
        'simplexmlelement::xpath' => true,
        'simplexmlelement::registerxpathnamespace' => true,
        'simplexmlelement::__get' => true,
        'simplexmlelement::__set' => true,
        'simplexmlelement::offsetget' => true,
        'simplexmlelement::offsetset' => true,
        'simplexmlelement::offsetexists' => true,
        'simplexmlelement::offsetunset' => true,
        'simplexmlelement::count' => true,
        'simplexmlelement::__tostring' => true,
        'simplexmlelement::children' => true,
        'simplexmlelement::attributes' => true,
        'simplexmlelement::getname' => true,
        // leftover of getName/attributes AOT (#27535 / #35798) — php-src sxe.c zim_simplexmlelement_getNamespaces
        'simplexmlelement::getnamespaces' => true,
        'simplexmlelement::getdocnamespaces' => true,
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
        if ('simplexmlelement::addattribute' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementAddChild('addAttribute');

            return;
        }
        // saveXML is a php-src FALIAS of asXML (#19413).
        if ('simplexmlelement::asxml' === $lc || 'simplexmlelement::savexml' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementAsXml(
                'simplexmlelement::savexml' === $lc ? 'saveXML' : 'asXML'
            );

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
        if ('simplexmlelement::__set' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementGet('__set');

            return;
        }
        if ('simplexmlelement::offsetget' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementOffsetGet();

            return;
        }
        if ('simplexmlelement::offsetset' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementOffsetGet('offsetSet');

            return;
        }
        if ('simplexmlelement::offsetexists' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementOffsetGet('offsetExists');

            return;
        }
        if ('simplexmlelement::offsetunset' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementOffsetGet('offsetUnset');

            return;
        }
        if ('simplexmlelement::count' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementCount();

            return;
        }
        if ('simplexmlelement::__tostring' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementToString();

            return;
        }
        if ('simplexmlelement::children' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementChildren();

            return;
        }
        if ('simplexmlelement::attributes' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementAttributes();

            return;
        }
        if ('simplexmlelement::getname' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementGetName();

            return;
        }
        if ('simplexmlelement::getnamespaces' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementGetNamespaces();

            return;
        }
        if ('simplexmlelement::getdocnamespaces' === $lc) {
            $context->functionProxies[$lc] = new Call\SimpleXMLElementGetDocNamespaces();
        }
    }
}
