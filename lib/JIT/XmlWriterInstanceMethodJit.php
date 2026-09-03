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
        // leftover of openMemory AOT (#19551 / #35872) — php-src zim_XMLWriter_openUri
        'xmlwriter::openuri' => true,
        // leftover of openMemory AOT (#19606 / #35872) — php-src zim_XMLWriter_toMemory
        'xmlwriter::tomemory' => true,
        // leftover of openUri AOT (#19606 / #35872) — php-src zim_XMLWriter_toUri
        'xmlwriter::touri' => true,
        // leftover of toMemory/toUri AOT (#35895 / #19606) — php-src zim_XMLWriter_toStream
        'xmlwriter::tostream' => true,
        'xmlwriter::startdocument' => true,
        'xmlwriter::startelement' => true,
        'xmlwriter::startelementns' => true,
        'xmlwriter::writeattribute' => true,
        'xmlwriter::writeattributens' => true,
        'xmlwriter::startattribute' => true,
        'xmlwriter::startattributens' => true,
        'xmlwriter::endattribute' => true,
        'xmlwriter::text' => true,
        'xmlwriter::startcdata' => true,
        'xmlwriter::endcdata' => true,
        'xmlwriter::startcomment' => true,
        'xmlwriter::endcomment' => true,
        'xmlwriter::startdtd' => true,
        'xmlwriter::enddtd' => true,
        'xmlwriter::writedtd' => true,
        'xmlwriter::writedtdelement' => true,
        'xmlwriter::startdtdelement' => true,
        'xmlwriter::enddtdelement' => true,
        'xmlwriter::writedtdattlist' => true,
        'xmlwriter::startdtdattlist' => true,
        'xmlwriter::enddtdattlist' => true,
        'xmlwriter::startdtdentity' => true,
        'xmlwriter::enddtdentity' => true,
        'xmlwriter::writedtdentity' => true,
        'xmlwriter::startpi' => true,
        'xmlwriter::endpi' => true,
        'xmlwriter::writepi' => true,
        'xmlwriter::writeraw' => true,
        'xmlwriter::writeelementns' => true,
        // leftover of writeElementNS AOT (#19371 / #35865) — php-src zim_XMLWriter_writeElement
        'xmlwriter::writeelement' => true,
        'xmlwriter::writecdata' => true,
        'xmlwriter::writecomment' => true,
        'xmlwriter::setindent' => true,
        'xmlwriter::setindentstring' => true,
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
        return UserScriptAotEnv::isActive();
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
        if ('xmlwriter::tomemory' === $lc) {
            if (!\PHPCompiler\CompilerVersion::supportsXmlWriterFactories()) {
                return;
            }
            $context->functionProxies[$lc] = new Call\XmlWriterToMemory();

            return;
        }
        if ('xmlwriter::touri' === $lc) {
            if (!\PHPCompiler\CompilerVersion::supportsXmlWriterFactories()) {
                return;
            }
            $context->functionProxies[$lc] = new Call\XmlWriterToUri();

            return;
        }
        if ('xmlwriter::tostream' === $lc) {
            if (!\PHPCompiler\CompilerVersion::supportsXmlWriterFactories()) {
                return;
            }
            $context->functionProxies[$lc] = new Call\XmlWriterToStream();

            return;
        }
        $methodLc = substr($lc, \strlen('xmlwriter::'));
        $context->functionProxies[$lc] = new Call\XmlWriterMethod($methodLc);
    }
}
