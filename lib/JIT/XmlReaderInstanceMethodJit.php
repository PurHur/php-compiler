<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;

/** Lazy registration for ext/xmlreader user-script AOT proxies (#27299). */
final class XmlReaderInstanceMethodJit
{
    /** @var array<string, true> */
    private const METHODS = [
        'xmlreader::fromstring' => true,
        'xmlreader::xml' => true,
        'xmlreader::read' => true,
    ];

    public static function isXmlReaderInstanceMethodProxy(string $proxyName): bool
    {
        $lc = strtolower(ltrim($proxyName, '\\'));

        return isset(self::METHODS[$lc]);
    }

    public static function isUserScriptAot(): bool
    {
        return \PHPCompiler\ext\xmlreader\JitXmlReaderUserScript::isUserScriptAot();
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
        if ('xmlreader::fromstring' === $lc) {
            $context->functionProxies[$lc] = new Call\XmlReaderFromString();

            return;
        }
        if ('xmlreader::xml' === $lc) {
            $context->functionProxies[$lc] = new Call\XmlReaderXML();

            return;
        }
        $methodLc = substr($lc, \strlen('xmlreader::'));
        $context->functionProxies[$lc] = new Call\XmlReaderMethod($methodLc);
    }
}
