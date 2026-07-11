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
}
