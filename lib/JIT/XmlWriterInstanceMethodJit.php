<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;

/** Lazy registration for ext/xmlwriter user-script AOT proxies (#19551). */
final class XmlWriterInstanceMethodJit
{
    /** @var array<string, true> */
    private const METHODS = [
        'xmlwriter::openmemory' => true,
        'xmlwriter::startdocument' => true,
        'xmlwriter::startelement' => true,
        'xmlwriter::writeattribute' => true,
        'xmlwriter::startattribute' => true,
        'xmlwriter::endattribute' => true,
        'xmlwriter::text' => true,
        'xmlwriter::fullendelement' => true,
        'xmlwriter::endelement' => true,
        'xmlwriter::enddocument' => true,
        'xmlwriter::outputmemory' => true,
        'xmlwriter::flush' => true,
    ];

    public static function isXmlWriterInstanceMethodProxy(string $proxyName): bool
    {
        $lc = strtolower(ltrim($proxyName, '\\'));

        return isset(self::METHODS[$lc]);
    }

    public static function isUserScriptAot(): bool
    {
        return \PHPCompiler\ext\xmlwriter\JitXmlWriterUserScript::isUserScriptAot();
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
        $methodLc = substr($lc, \strlen('xmlwriter::'));
        $context->functionProxies[$lc] = new Call\XmlWriterMethod($methodLc);
    }
}
