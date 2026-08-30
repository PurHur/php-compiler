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
        // leftover of fromString AOT (#35900 / #27299) — php-src zim_xmlreader_fromUri
        'xmlreader::fromuri' => true,
        // leftover of fromString AOT (#35900 / #27299) — php-src zim_xmlreader_fromStream
        'xmlreader::fromstream' => true,
        'xmlreader::xml' => true,
        // leftover of fromUri AOT (#35907 / #27299) — php-src zim_xmlreader_open
        'xmlreader::open' => true,
        'xmlreader::read' => true,
        // leftover of fromString read (#35908 / #27299) — php-src zim_XMLReader_readInnerXml
        'xmlreader::readinnerxml' => true,
        // leftover of fromString read (#35908 / #27299) — php-src zim_XMLReader_readOuterXml
        'xmlreader::readouterxml' => true,
        // leftover of fromString/readInnerXml (#35917 / #27299) — php-src zim_XMLReader_readString
        'xmlreader::readstring' => true,
        // leftover of fromString/open (#35911 / #27299) — php-src zim_XMLReader_expand
        'xmlreader::expand' => true,
        // leftover of fromString/read (#35918 / #27299) — php-src zim_XMLReader_getAttribute
        'xmlreader::getattribute' => true,
        // leftover of getAttribute (#35924 / #35918 / #27299) — php-src zim_XMLReader_getAttributeNs
        'xmlreader::getattributens' => true,
        // leftover of getAttribute (#35924 / #35918 / #27299) — php-src zim_XMLReader_getAttributeNo
        'xmlreader::getattributeno' => true,
        // leftover of fromString/getAttribute (#35930 / #27299) — php-src zim_XMLReader_lookupNamespace
        'xmlreader::lookupnamespace' => true,
        // leftover of fromString/read (#35926 / #27299) — php-src zim_XMLReader_next
        'xmlreader::next' => true,
        // leftover of fromString/read (#35959 / #27299) — php-src zim_XMLReader_isValid
        'xmlreader::isvalid' => true,
        // leftover of fromString/open (#35935 / #27299) — php-src zim_XMLReader_close
        'xmlreader::close' => true,
        // leftover of getAttribute (#35941 / #35918 / #27299) — php-src zim_XMLReader_moveToAttribute
        'xmlreader::movetoattribute' => true,
        // leftover of moveToAttribute (#35946 / #35941 / #27299) — php-src zim_XMLReader_moveToAttributeNo
        'xmlreader::movetoattributeno' => true,
        // leftover of moveToAttribute (#35948 / #35941 / #27299) — php-src zim_XMLReader_moveToFirstAttribute
        'xmlreader::movetofirstattribute' => true,
        // leftover of moveToAttribute (#35951 / #35941 / #27299) — php-src zim_XMLReader_moveToAttributeNs
        'xmlreader::movetoattributens' => true,
        // leftover of moveToAttribute (#35940 / #27299) — php-src zim_XMLReader_moveToElement
        'xmlreader::movetoelement' => true,
        // leftover of moveToAttribute (#35952 / #35941 / #27299) — php-src zim_XMLReader_moveToNextAttribute
        'xmlreader::movetonextattribute' => true,
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
        if ('xmlreader::fromuri' === $lc) {
            if (!\PHPCompiler\CompilerVersion::supportsXmlReaderFactories()) {
                return;
            }
            $context->functionProxies[$lc] = new Call\XmlReaderFromUri();

            return;
        }
        if ('xmlreader::fromstream' === $lc) {
            if (!\PHPCompiler\CompilerVersion::supportsXmlReaderFactories()) {
                return;
            }
            $context->functionProxies[$lc] = new Call\XmlReaderFromStream();

            return;
        }
        if ('xmlreader::xml' === $lc) {
            $context->functionProxies[$lc] = new Call\XmlReaderXML();

            return;
        }
        if ('xmlreader::open' === $lc) {
            $context->functionProxies[$lc] = new Call\XmlReaderOpen();

            return;
        }
        $methodLc = substr($lc, \strlen('xmlreader::'));
        $context->functionProxies[$lc] = new Call\XmlReaderMethod($methodLc);
    }
}
