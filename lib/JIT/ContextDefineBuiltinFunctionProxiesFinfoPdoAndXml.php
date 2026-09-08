<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;

/**
 * finfo / PDO / XMLReader / XMLWriter / Dom\TokenList thin-AOT Call proxies for
 * {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxiesDateAndXml} so the
 * fileinfo / PDO / XML catalog stays a separate TU from DateTime* wiring
 * (split-TU / size-budget ratchet toward DateAndXml ≤ 140 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesFinfoPdoAndXml;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after DateAndXml registration.
 *
 * No new C ABI. php-src analogy: zim_finfo_* / zim_PDO_* / XMLReader / XMLWriter
 * method tables live in ext/fileinfo/, ext/pdo/, ext/xmlreader/, ext/xmlwriter/,
 * ext/dom/ beside the executor rather than inside a monolithic MINIT catalog
 * (ext/fileinfo/fileinfo.c, ext/pdo/pdo.c, ext/xmlreader/php_xmlreader.c,
 * ext/xmlwriter/php_xmlwriter.c, ext/dom/php_dom.c).
 */
trait ContextDefineBuiltinFunctionProxiesFinfoPdoAndXml
{
    private function defineBuiltinFunctionProxiesFinfoPdoAndXml(): void
    {
        // Locale::* + NumberFormatter / IntlDateFormatter / Collator / Normalizer /
        // MessageFormatter / Transliterator thin-AOT Call proxies:
        // registered by ext/intl/Module::jitInit (#36204 / #20760 / #27385 / #27361 / #28649 / #28654 / #28655 / #28657).
        // finfo::__construct / finfo::file / finfo::buffer / finfo::set_flags — thin AOT (#27196, #28660, #34688).
        $this->functionProxies['finfo::__construct'] = new Call\FinfoConstruct();
        $this->functionProxies['finfo::file'] = new Call\FinfoFile();
        $this->functionProxies['finfo::buffer'] = new Call\FinfoBuffer();
        $this->functionProxies['finfo::set_flags'] = new Call\FinfoSetFlags();
        // PDO — avoid ExternalMethod silent NULL / fake connect (#27619).
        $this->functionProxies['pdo::__construct'] = new Call\PdoConstruct();
        $this->functionProxies['pdo::getavailabledrivers'] = new Call\PdoGetAvailableDrivers();
        $this->functionProxies['pdo::quote'] = new Call\PdoQuote();
        // Dom\XMLDocument / Dom\HTMLDocument::createFromString / createFromFile +
        // DOMDocument::__construct: ext/dom/Module::jitInit (#36204 / #27108, #27300, #33607).
        // XMLReader::XML / fromString / read — avoid ExternalMethod silent NULL on thin AOT (#27299, #28670).
        // XML() exists on all profiles; fromString is PROFILE≥8.4 only.
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::xml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::open');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::read');
        // leftover of fromString read (#35908 / #27299) — php-src readInnerXml / readOuterXml
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readinnerxml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readouterxml');
        // leftover of fromString/readInnerXml (#35917 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readstring');
        // leftover of fromString/open (#35911 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::expand');
        // leftover of fromString/read (#35926 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::next');
        // leftover of fromString/read (#35959 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::isvalid');
        // leftover of fromString/read (#35965 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setparserproperty');
        // leftover of fromString (#35971 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschemasource');
        // leftover of fromString (#35935 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::close');
        // leftover of getAttribute (#35941 / #35918 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattribute');
        // leftover of moveToAttribute (#35946 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributeno');
        // leftover of moveToAttribute (#35948 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetofirstattribute');
        // leftover of moveToAttribute (#35951 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributens');
        // leftover of moveToAttribute (#35940 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoelement');
        // leftover of moveToAttribute (#35952 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetonextattribute');
        // leftover of fromString/read (#35962 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::getparserproperty');
        if (CompilerVersion::supportsXmlReaderFactories()) {
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstring');
            // leftover of fromString (#35900 / #27299)
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromuri');
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstream');
        }
        // XMLWriter::toMemory / toUri / toStream — leftover of openMemory/openUri (#19606 / #35872 / #35895).
        if (CompilerVersion::supportsXmlWriterFactories()) {
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tomemory');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::touri');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tostream');
        }
        if (CompilerVersion::supportsDomTokenList()) {
            DomInstanceMethodJit::registerKnownProxies($this);
        }
    }
}
