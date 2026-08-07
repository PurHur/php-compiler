<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
use PHPCompiler\ext\xsl\JitXsltUserScript;

/** Lazy registration for XSLTProcessor user-script AOT proxies (#20392). */
final class XsltInstanceMethodJit
{
    /** @var array<string, true> */
    private const METHODS = [
        'xsltprocessor::hasexsltsupport' => true,
        'xsltprocessor::setsecurityprefs' => true,
        'xsltprocessor::getsecurityprefs' => true,
        'xsltprocessor::setprofiling' => true,
        'xsltprocessor::importstylesheet' => true,
        'xsltprocessor::transformtoxml' => true,
    ];

    public static function isXsltInstanceMethodProxy(string $proxyName): bool
    {
        $lc = strtolower(ltrim($proxyName, '\\'));

        return isset(self::METHODS[$lc]);
    }

    public static function isUserScriptAot(): bool
    {
        return JitXsltUserScript::isUserScriptAot();
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
        $methodLc = substr($lc, \strlen('xsltprocessor::'));
        $context->functionProxies[$lc] = new Call\XsltMethod($methodLc);
    }
}
