<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PHP 8.4 Dom\ living-standard namespace (php-src ext/dom/html_document.c; #6506).
 *
 * Reuses {@see DomRegistry} tree state from legacy DOMDocument paths.
 */
final class VmDomLiving
{
    public const CLASS_NODE = 'dom\\node';

    public const CLASS_ELEMENT = 'dom\\element';

    public const CLASS_HTML_ELEMENT = 'dom\\htmlelement';

    public const CLASS_DOCUMENT = 'dom\\document';

    public const CLASS_HTML_DOCUMENT = 'dom\\htmldocument';

    public const CLASS_XML_DOCUMENT = 'dom\\xmldocument';

    public const PROP_BODY = 'body';

    public const PROP_HEAD = 'head';

    public const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public static function isLivingDocument(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);

        return self::CLASS_HTML_DOCUMENT === $lc || self::CLASS_XML_DOCUMENT === $lc;
    }

    public static function isLivingDocumentClass(string $classLc): bool
    {
        $lc = strtolower($classLc);

        return self::CLASS_HTML_DOCUMENT === $lc || self::CLASS_XML_DOCUMENT === $lc;
    }

    public static function allocateHtmlDocument(Context $ctx): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_HTML_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('Dom\\HTMLDocument is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        VmDom::ensureDocument($entry);
        VmDom::ensureChildNodesList($ctx, $entry);
        $state = DomRegistry::state($entry);
        $state->isHtmlDocument = true;
        self::applyLivingElementClassMap($state);

        return $entry;
    }

    public static function allocateXmlDocument(Context $ctx): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_XML_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('Dom\\XMLDocument is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        VmDom::ensureDocument($entry);
        VmDom::ensureChildNodesList($ctx, $entry);
        $state = DomRegistry::state($entry);
        $state->isHtmlDocument = false;
        self::applyLivingXmlElementClassMap($state);

        return $entry;
    }

    public static function createFromString(
        Context $ctx,
        string $source,
        int $options = 0,
        ?string $overrideEncoding = null,
        ?Frame $frame = null
    ): Variable {
        self::assertValidHtmlParseOptions($options);
        if (null !== $overrideEncoding && '' === $overrideEncoding) {
            throw new \ValueError('Dom\\HTMLDocument::createFromString(): Argument #3 ($overrideEncoding) must not be empty');
        }

        $document = self::allocateHtmlDocument($ctx);
        if (null !== $overrideEncoding) {
            DomRegistry::state($document)->encoding = $overrideEncoding;
        }
        $ok = VmDom::loadHTML($ctx, $document, $source, $options, $frame);
        if (!$ok) {
            throw new \DOMException('Dom\\HTMLDocument::createFromString(): failed to parse HTML');
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($document);

        return $var;
    }

    public static function createEmpty(Context $ctx, string $encoding = 'UTF-8'): Variable
    {
        if ('' === $encoding) {
            throw new \ValueError('Dom\\HTMLDocument::createEmpty(): Argument #1 ($encoding) must not be empty');
        }

        return self::createFromString(
            $ctx,
            '<!DOCTYPE html><html><head></head><body></body></html>',
            0,
            $encoding
        );
    }

    /**
     * Dom\XMLDocument::createFromString() — php-src ext/dom/xml_document.c (#19581).
     */
    public static function createXmlFromString(
        Context $ctx,
        string $source,
        int $options = 0,
        ?string $overrideEncoding = null,
        ?Frame $frame = null
    ): Variable {
        if ('' === $source) {
            throw new \ValueError('Dom\\XMLDocument::createFromString(): Argument #1 ($source) must not be empty');
        }
        self::assertValidXmlParseOptions($options);
        if (null !== $overrideEncoding) {
            self::assertValidDocumentEncoding(
                $overrideEncoding,
                'Dom\\XMLDocument::createFromString()',
                3,
                'overrideEncoding'
            );
        }

        $document = self::allocateXmlDocument($ctx);
        if (null !== $overrideEncoding) {
            DomRegistry::state($document)->encoding = $overrideEncoding;
        }
        $ok = VmDom::loadXML($ctx, $document, $source, $frame);
        if (!$ok) {
            throw new \DOMException(
                'Invalid State Error',
                DomExceptionConstants::INVALID_STATE_ERR
            );
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($document);

        return $var;
    }

    /**
     * Dom\XMLDocument::createEmpty() — php-src ext/dom/xml_document.c (#19581).
     *
     * Unlike HTMLDocument::createEmpty(), this yields a document with no root element.
     */
    public static function createXmlEmpty(
        Context $ctx,
        string $version = '1.0',
        string $encoding = 'UTF-8'
    ): Variable {
        self::assertValidDocumentEncoding(
            $encoding,
            'Dom\\XMLDocument::createEmpty()',
            2,
            'encoding'
        );

        $document = self::allocateXmlDocument($ctx);
        $state = DomRegistry::state($document);
        $state->xmlVersion = $version;
        $state->encoding = $encoding;
        if ($document->hasProperty(VmDom::PROP_XML_VERSION)) {
            $document->getProperty(VmDom::PROP_XML_VERSION)->string($version);
        }
        if ($document->hasProperty(VmDom::PROP_ENCODING)) {
            $document->getProperty(VmDom::PROP_ENCODING)->string($encoding);
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($document);

        return $var;
    }

    public static function findDirectChildElementByLocalName(ObjectEntry $parent, string $localName): ?ObjectEntry
    {
        $localName = strtolower($localName);
        $state = DomRegistry::state($parent);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || !VmDom::isElement($child)) {
                continue;
            }
            if ($localName === strtolower(DomRegistry::state($child)->nodeName)) {
                return $child;
            }
        }

        return null;
    }

    public static function htmlRootElement(ObjectEntry $document): ?ObjectEntry
    {
        VmDom::ensureDocument($document);
        $rootVar = $document->getProperty(VmDom::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $rootVar->type) {
            return null;
        }
        $root = $rootVar->toObject();
        if (!VmDom::isElement($root)) {
            return null;
        }

        return $root;
    }

    public static function htmlBodyElement(ObjectEntry $document): ?ObjectEntry
    {
        $html = self::htmlRootElement($document);
        if (null === $html) {
            return null;
        }

        return self::findDirectChildElementByLocalName($html, 'body');
    }

    public static function htmlHeadElement(ObjectEntry $document): ?ObjectEntry
    {
        $html = self::htmlRootElement($document);
        if (null === $html) {
            return null;
        }

        return self::findDirectChildElementByLocalName($html, 'head');
    }

    private static function applyLivingElementClassMap(DomNodeState $state): void
    {
        if (isset($state->nodeClassMap[VmDom::CLASS_ELEMENT])) {
            return;
        }
        $state->nodeClassMap[VmDom::CLASS_ELEMENT] = self::CLASS_HTML_ELEMENT;
    }

    private static function applyLivingXmlElementClassMap(DomNodeState $state): void
    {
        if (isset($state->nodeClassMap[VmDom::CLASS_ELEMENT])) {
            return;
        }
        $state->nodeClassMap[VmDom::CLASS_ELEMENT] = self::CLASS_ELEMENT;
    }

    private static function assertValidHtmlParseOptions(int $options): void
    {
        $allowed = LibxmlConstants::LIBXML_HTML_NOIMPLIED
            | LibxmlConstants::LIBXML_COMPACT
            | LibxmlConstants::LIBXML_NOERROR
            | DomLivingConstants::HTML_NO_DEFAULT_NS;
        if (0 !== ($options & ~$allowed)) {
            throw new \ValueError('Dom\\HTMLDocument::createFromString(): Argument #2 ($options) contains an invalid option');
        }
    }

    /** php-src ext/dom/xml_document.c check_options_validity(). */
    private static function assertValidXmlParseOptions(int $options): void
    {
        $allowed = LibxmlConstants::LIBXML_RECOVER
            | LibxmlConstants::LIBXML_NOENT
            | LibxmlConstants::LIBXML_DTDLOAD
            | LibxmlConstants::LIBXML_DTDATTR
            | LibxmlConstants::LIBXML_DTDVALID
            | LibxmlConstants::LIBXML_NOERROR
            | LibxmlConstants::LIBXML_NOWARNING
            | LibxmlConstants::LIBXML_NOBLANKS
            | LibxmlConstants::LIBXML_NSCLEAN
            | LibxmlConstants::LIBXML_NOCDATA
            | LibxmlConstants::LIBXML_NONET
            | LibxmlConstants::LIBXML_PEDANTIC
            | LibxmlConstants::LIBXML_COMPACT
            | LibxmlConstants::LIBXML_PARSEHUGE
            | LibxmlConstants::LIBXML_BIGLINES;
        if (0 !== ($options & ~$allowed)) {
            throw new \ValueError('Dom\\XMLDocument::createFromString(): Argument #2 ($options) contains invalid flags (allowed flags: LIBXML_RECOVER, LIBXML_NOENT, LIBXML_DTDLOAD, LIBXML_DTDATTR, LIBXML_DTDVALID, LIBXML_NOERROR, LIBXML_NOWARNING, LIBXML_NOBLANKS, LIBXML_NSCLEAN, LIBXML_NOCDATA, LIBXML_NONET, LIBXML_PEDANTIC, LIBXML_COMPACT, LIBXML_PARSEHUGE, LIBXML_BIGLINES)');
        }
    }

    /**
     * Approximate libxml xmlFindCharEncodingHandler() for factory args
     * (php-src ext/dom/xml_document.c).
     */
    private static function assertValidDocumentEncoding(
        string $encoding,
        string $method,
        int $argNum,
        string $paramName
    ): void {
        if ('' === $encoding) {
            throw new \ValueError(sprintf(
                '%s: Argument #%d ($%s) must not be empty',
                $method,
                $argNum,
                $paramName
            ));
        }
        $normalized = strtoupper(str_replace(['-', '_'], '', $encoding));
        static $known = [
            'UTF8' => true,
            'UTF16' => true,
            'UTF16LE' => true,
            'UTF16BE' => true,
            'ASCII' => true,
            'USASCII' => true,
            'ISO88591' => true,
            'ISO88592' => true,
            'ISO88593' => true,
            'ISO88594' => true,
            'ISO88595' => true,
            'ISO88596' => true,
            'ISO88597' => true,
            'ISO88598' => true,
            'ISO88599' => true,
            'ISO885910' => true,
            'ISO885913' => true,
            'ISO885914' => true,
            'ISO885915' => true,
            'ISO885916' => true,
            'WINDOWS1250' => true,
            'WINDOWS1251' => true,
            'WINDOWS1252' => true,
            'CP1250' => true,
            'CP1251' => true,
            'CP1252' => true,
            'SHIFTJIS' => true,
            'SJIS' => true,
            'EUCJP' => true,
            'GB2312' => true,
            'GBK' => true,
            'BIG5' => true,
            'KOI8R' => true,
            'KOI8U' => true,
        ];
        if (!isset($known[$normalized])) {
            $msg = 2 === $argNum
                ? sprintf('%s: Argument #%d ($%s) is not a valid document encoding', $method, $argNum, $paramName)
                : sprintf('%s: Argument #%d ($%s) must be a valid document encoding', $method, $argNum, $paramName);
            throw new \ValueError($msg);
        }
    }
}
