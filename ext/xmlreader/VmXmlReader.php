<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * XMLReader pull parser — PHP-in-PHP tokenizer (php-src ext/xmlreader/php_xmlreader.c; #6135).
 */
final class VmXmlReader
{
    public const CLASS_LC = 'xmlreader';

    public const PROP_ATTRIBUTE_COUNT = 'attributeCount';
    public const PROP_BASE_URI = 'baseURI';
    public const PROP_DEPTH = 'depth';
    public const PROP_HAS_ATTRIBUTES = 'hasAttributes';
    public const PROP_HAS_VALUE = 'hasValue';
    public const PROP_IS_DEFAULT = 'isDefault';
    public const PROP_IS_EMPTY_ELEMENT = 'isEmptyElement';
    public const PROP_LOCAL_NAME = 'localName';
    public const PROP_NAME = 'name';
    public const PROP_NAMESPACE_URI = 'namespaceURI';
    public const PROP_NODE_TYPE = 'nodeType';
    public const PROP_PREFIX = 'prefix';
    public const PROP_VALUE = 'value';
    public const PROP_XML_LANG = 'xmlLang';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = \PHPCfg\Func::FLAG_PUBLIC;
        $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;

        $entry = new ClassEntry('XMLReader');
        $entry->methods['open'] = new XmlReaderOpen();
        $entry->methodVisibility['open'] = $pubStatic;
        $entry->methodNames['open'] = 'open';
        $entry->methods['xml'] = new XmlReaderXML();
        $entry->methodVisibility['xml'] = $pubStatic;
        $entry->methodNames['xml'] = 'XML';
        $entry->methods['read'] = new XmlReaderRead();
        $entry->methodVisibility['read'] = $pub;
        $entry->methodNames['read'] = 'read';
        $entry->methods['close'] = new XmlReaderClose();
        $entry->methodVisibility['close'] = $pub;
        $entry->methodNames['close'] = 'close';
        $entry->methods['getattribute'] = new XmlReaderGetAttribute();
        $entry->methodVisibility['getattribute'] = $pub;
        $entry->methodNames['getattribute'] = 'getAttribute';
        $entry->methods['isvalid'] = new XmlReaderIsValid();
        $entry->methodVisibility['isvalid'] = $pub;
        $entry->methodNames['isvalid'] = 'isValid';
        $entry->methods['expand'] = new XmlReaderExpand();
        $entry->methodVisibility['expand'] = $pub;
        $entry->methodNames['expand'] = 'expand';

        if (CompilerVersion::supportsXmlReaderFactories()) {
            $entry->methods['fromstring'] = new XmlReaderFromString();
            $entry->methodVisibility['fromstring'] = $pubStatic;
            $entry->methodNames['fromstring'] = 'fromString';
            $entry->methods['fromuri'] = new XmlReaderFromUri();
            $entry->methodVisibility['fromuri'] = $pubStatic;
            $entry->methodNames['fromuri'] = 'fromUri';
            $entry->methods['fromstream'] = new XmlReaderFromStream();
            $entry->methodVisibility['fromstream'] = $pubStatic;
            $entry->methodNames['fromstream'] = 'fromStream';
        }

        foreach (XmlReaderConstants::classConstants() as $name => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $entry->constants[$name] = $var;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    public static function open(Context $ctx, string $uri, ?Frame $frame = null): ?ObjectEntry
    {
        $contents = self::readUriContents($ctx, $uri, $frame);
        if (null === $contents) {
            return null;
        }

        return self::openFromString($ctx, $uri, $contents, $frame);
    }

    /**
     * XMLReader::open() instance form — reset parser state on an existing reader (#19330).
     */
    public static function openOnto(Context $ctx, ObjectEntry $entry, string $uri, ?Frame $frame = null): bool
    {
        self::requireClass($entry, 'XMLReader::open()');
        $contents = self::readUriContents($ctx, $uri, $frame);
        if (null === $contents) {
            return false;
        }
        self::bindParsedSource($ctx, $entry, $uri, $contents, $frame);

        return true;
    }

    public static function openFromString(Context $ctx, string $uri, string $data, ?Frame $frame = null): ?ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('XMLReader is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        self::bindParsedSource($ctx, $entry, $uri, $data, $frame);

        return $entry;
    }

    private static function readUriContents(Context $ctx, string $uri, ?Frame $frame): ?string
    {
        $contents = VmFsReadNative::read($uri);
        if (false === $contents) {
            self::warn($ctx, 'XMLReader::open(): Unable to open source data', $frame);

            return null;
        }

        return $contents;
    }

    /**
     * XMLReader::XML() static factory — php-src zim_xmlreader_XML / xmlReaderForMemory (#19308).
     */
    public static function xml(Context $ctx, string $source, ?Frame $frame = null): ?ObjectEntry
    {
        return self::openFromString($ctx, '', $source, $frame);
    }

    /**
     * XMLReader::XML() instance form — reset parser state on an existing reader (#19308).
     */
    public static function xmlOnto(Context $ctx, ObjectEntry $entry, string $source, ?Frame $frame = null): bool
    {
        self::requireClass($entry, 'XMLReader::XML()');
        self::bindParsedSource($ctx, $entry, '', $source, $frame);

        return true;
    }

    /**
     * XMLReader::fromString() — always-static in-memory factory (php-src; #19607).
     */
    public static function fromString(Context $ctx, string $source, ?Frame $frame = null): ObjectEntry
    {
        $reader = self::openFromString($ctx, '', $source, $frame);
        if (null === $reader) {
            throw new \Error('XMLReader::fromString(): Unable to open source data');
        }

        return $reader;
    }

    /**
     * XMLReader::fromUri() — always-static URI factory (php-src; #19607).
     */
    public static function fromUri(Context $ctx, string $uri, ?Frame $frame = null): ?ObjectEntry
    {
        return self::open($ctx, $uri, $frame);
    }

    /**
     * XMLReader::fromStream() — always-static stream factory (php-src; #19607).
     */
    public static function fromStream(
        Context $ctx,
        Variable $streamVar,
        ?string $documentUri = null,
        ?Frame $frame = null
    ): ObjectEntry {
        $handle = ResourceSupport::resolveHandle($streamVar);
        if (null === $handle) {
            throw new \TypeError('XMLReader::fromStream(): Argument #1 ($stream) must be of type resource');
        }
        $contents = VmFs::streamGetContents($handle);
        if (false === $contents) {
            throw new \Error('XMLReader::fromStream(): Unable to read source data');
        }
        $uri = $documentUri ?? '';
        $reader = self::openFromString($ctx, $uri, $contents, $frame);
        if (null === $reader) {
            throw new \Error('XMLReader::fromStream(): Unable to open source data');
        }

        return $reader;
    }

    private static function bindParsedSource(
        Context $ctx,
        ObjectEntry $entry,
        string $uri,
        string $data,
        ?Frame $frame
    ): void {
        $valid = VmXml::validateAndReport($ctx, $data, $frame);
        $events = [];
        if ($valid) {
            try {
                $events = self::tokenize($data);
            } catch (\LogicException) {
                $valid = false;
            }
        }

        $state = new XmlReaderState();
        $state->uri = $uri;
        $state->valid = $valid;
        $state->events = $events;
        $state->position = -1;
        $state->current = null;
        $state->closed = false;
        XmlReaderRegistry::attach($entry, $state);
    }

    private static function requireClass(ObjectEntry $entry, string $label): void
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XMLReader, %s given', $label, $entry->class->name));
        }
    }

    public static function read(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        if ($state->closed) {
            return false;
        }
        ++$state->position;
        if ($state->position >= \count($state->events)) {
            $state->current = null;

            return false;
        }
        $state->current = $state->events[$state->position];

        return true;
    }

    public static function close(ObjectEntry $entry): bool
    {
        $state = XmlReaderRegistry::state($entry);
        $state->closed = true;
        $state->current = null;
        $state->position = \count($state->events);
        XmlReaderRegistry::detach($entry);

        return true;
    }

    public static function getAttribute(ObjectEntry $entry, string $name): ?string
    {
        $state = XmlReaderRegistry::state($entry);
        $current = $state->current;
        if (null === $current || XmlReaderConstants::ELEMENT !== $current->nodeType) {
            return null;
        }

        return $current->attributes[$name] ?? null;
    }

    public static function isValid(ObjectEntry $entry): bool
    {
        return XmlReaderRegistry::state($entry)->valid;
    }

    /**
     * XMLReader::expand() — php-src zim_XMLReader_expand / xmlTextReaderExpand (#19394).
     *
     * @return ObjectEntry|false
     */
    public static function expand(
        Context $ctx,
        ObjectEntry $entry,
        ?ObjectEntry $baseNode = null,
        ?Frame $frame = null
    ): ObjectEntry|false {
        self::requireClass($entry, 'XMLReader::expand()');
        if (!XmlReaderRegistry::has($entry)) {
            throw new \Error('Data must be loaded before expanding');
        }
        if (null !== $baseNode && !VmDom::isDomNode($baseNode)) {
            throw new \TypeError(
                'XMLReader::expand(): Argument #1 ($baseNode) must be of type ?DOMNode, '
                .$baseNode->class->name.' given'
            );
        }
        $ownerDocument = self::resolveExpandDocument($baseNode);
        $state = XmlReaderRegistry::state($entry);
        $node = XmlReaderExpandHelper::expandAt($ctx, $state->events, $state->position, $ownerDocument);
        if (false === $node) {
            self::warn($ctx, 'XMLReader::expand(): An Error Occurred while expanding', $frame);

            return false;
        }

        return $node;
    }

    private static function resolveExpandDocument(?ObjectEntry $baseNode): ?ObjectEntry
    {
        if (null === $baseNode) {
            return null;
        }
        if (VmDom::isDocument($baseNode)) {
            return $baseNode;
        }

        return VmDom::ownerDocumentEntry($baseNode);
    }

    public static function requireReader(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XMLReader, %s given', $label, $entry->class->name));
        }
        if (!XmlReaderRegistry::has($entry)) {
            throw new \LogicException($label.'(): XMLReader has no parser state');
        }

        return $entry;
    }

    public static function currentEvent(ObjectEntry $entry): ?XmlReaderEvent
    {
        if (!XmlReaderRegistry::has($entry)) {
            return null;
        }

        return XmlReaderRegistry::state($entry)->current;
    }

    /** @return list<XmlReaderEvent> */
    public static function tokenize(string $data): array
    {
        $events = [];
        $trimmed = trim($data);
        if ('' === $trimmed) {
            return $events;
        }

        $pos = 0;
        $len = \strlen($trimmed);
        while ($pos < $len) {
            $pos = self::skipWhitespace($trimmed, $pos);
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $trimmed[$pos]) {
                throw new \LogicException('XMLReader: expected element start');
            }
            if ($pos + 1 < $len && '?' === $trimmed[$pos + 1]) {
                self::consumeXmlDeclaration($trimmed, $pos);

                continue;
            }
            if ($pos + 1 < $len && '!' === $trimmed[$pos + 1]) {
                if (str_starts_with(substr($trimmed, $pos), '<!--')) {
                    self::consumeComment($trimmed, $pos);

                    continue;
                }
                if (str_starts_with(substr($trimmed, $pos), '<![CDATA[')) {
                    self::tokenizeCdata($trimmed, $pos, $events, 0);

                    continue;
                }
                if (str_starts_with(substr($trimmed, $pos), '<!DOCTYPE')) {
                    self::consumeDoctype($trimmed, $pos);

                    continue;
                }
            }
            if ($pos + 1 < $len && '/' === $trimmed[$pos + 1]) {
                throw new \LogicException('XMLReader: unexpected end tag');
            }

            self::tokenizeElement($trimmed, $pos, $events, 0);
        }

        return $events;
    }

    /**
     * @param list<XmlReaderEvent> $events
     */
    private static function tokenizeElement(string $data, int &$pos, array &$events, int $depth): void
    {
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?(\/?)>/s', $data, $open, 0, $pos)) {
            throw new \LogicException('XMLReader: malformed element');
        }

        $rawName = $open[1];
        $attrSpec = $open[2] ?? '';
        $selfClose = isset($open[3]) && '/' === $open[3];
        $attrs = self::parseAttributes($attrSpec);
        $nameParts = self::splitQName($rawName);
        $events[] = self::makeEvent(
            XmlReaderConstants::ELEMENT,
            $rawName,
            '',
            $attrs,
            $depth,
            $selfClose,
            $nameParts
        );

        $contentStart = $pos + \strlen($open[0]);
        if ($selfClose) {
            $pos = $contentStart;

            return;
        }

        $end = VmXml::findElementEndForStruct($data, $pos);
        if (null === $end) {
            throw new \LogicException('XMLReader: unclosed element');
        }

        $closeTag = '</'.$rawName.'>';
        $innerEnd = $end - \strlen($closeTag);
        $scan = $contentStart;
        while ($scan < $innerEnd) {
            $scan = self::skipWhitespace($data, $scan);
            if ($scan >= $innerEnd) {
                break;
            }
            if ('<' !== $data[$scan]) {
                $textEnd = strpos($data, '<', $scan);
                if (false === $textEnd || $textEnd > $innerEnd) {
                    $textEnd = $innerEnd;
                }
                $text = substr($data, $scan, $textEnd - $scan);
                if ('' !== $text) {
                    $events[] = self::makeEvent(
                        XmlReaderConstants::TEXT,
                        '#text',
                        $text,
                        [],
                        $depth + 1,
                        false,
                        ['local' => '#text', 'prefix' => '', 'uri' => '']
                    );
                }
                $scan = $textEnd;

                continue;
            }
            if ($scan + 1 < $innerEnd && '!' === $data[$scan + 1]) {
                if (str_starts_with(substr($data, $scan), '<![CDATA[')) {
                    self::tokenizeCdata($data, $scan, $events, $depth + 1);

                    continue;
                }
                if (str_starts_with(substr($data, $scan), '<!--')) {
                    self::consumeComment($data, $scan);

                    continue;
                }
            }
            self::tokenizeElement($data, $scan, $events, $depth + 1);
        }

        $events[] = self::makeEvent(
            XmlReaderConstants::END_ELEMENT,
            $rawName,
            '',
            [],
            $depth,
            false,
            $nameParts
        );
        $pos = $end;
    }

    /**
     * @param list<XmlReaderEvent> $events
     */
    private static function tokenizeCdata(string $data, int &$pos, array &$events, int $depth): void
    {
        $parsed = VmXml::parseCdataSectionAt($data, $pos);
        if (null === $parsed) {
            throw new \LogicException('XMLReader: malformed CDATA');
        }
        $events[] = self::makeEvent(
            XmlReaderConstants::CDATA,
            '#cdata-section',
            $parsed['data'],
            [],
            $depth,
            false,
            ['local' => '#cdata-section', 'prefix' => '', 'uri' => '']
        );
        $pos = $parsed['end'];
    }

    private static function consumeComment(string $data, int &$pos): void
    {
        $parsed = VmXml::parseCommentAt($data, $pos);
        if (null === $parsed) {
            throw new \LogicException('XMLReader: malformed comment');
        }
        $pos = $parsed['end'];
    }

    private static function consumeXmlDeclaration(string $data, int &$pos): void
    {
        $end = strpos($data, '?>', $pos + 2);
        if (false === $end) {
            throw new \LogicException('XMLReader: malformed XML declaration');
        }
        $pos = $end + 2;
    }

    private static function consumeDoctype(string $data, int &$pos): void
    {
        $end = strpos($data, '>', $pos + 9);
        if (false === $end) {
            throw new \LogicException('XMLReader: malformed DOCTYPE');
        }
        $pos = $end + 1;
    }

    /**
     * @param array<string, string> $attrs
     * @param array{local: string, prefix: string, uri: string} $nameParts
     */
    private static function makeEvent(
        int $nodeType,
        string $name,
        string $value,
        array $attrs,
        int $depth,
        bool $isEmptyElement,
        array $nameParts
    ): XmlReaderEvent {
        $hasValue = '' !== $value;
        $attrCount = \count($attrs);

        return new XmlReaderEvent(
            $nodeType,
            $name,
            $value,
            $attrs,
            $depth,
            $isEmptyElement,
            $hasValue,
            $attrCount > 0,
            $attrCount,
            $nameParts['local'],
            $nameParts['prefix'],
            $nameParts['uri']
        );
    }

    /** @return array{local: string, prefix: string, uri: string} */
    private static function splitQName(string $qName): array
    {
        $colon = strpos($qName, ':');
        if (false === $colon) {
            return ['local' => $qName, 'prefix' => '', 'uri' => ''];
        }

        return [
            'local' => substr($qName, $colon + 1),
            'prefix' => substr($qName, 0, $colon),
            'uri' => '',
        ];
    }

    /** @return array<string, string> */
    private static function parseAttributes(string $attrSpec): array
    {
        $attrs = [];
        if ('' === trim($attrSpec)) {
            return $attrs;
        }
        if (!preg_match_all('/\G\s+([A-Za-z_][\w:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $attrSpec, $matches, PREG_SET_ORDER)) {
            return $attrs;
        }
        foreach ($matches as $match) {
            $attrs[$match[1]] = $match[2] ?? $match[3];
        }

        return $attrs;
    }

    private static function skipWhitespace(string $data, int $pos): int
    {
        $len = \strlen($data);
        while ($pos < $len && ctype_space($data[$pos])) {
            ++$pos;
        }

        return $pos;
    }

    private static function warn(Context $ctx, string $message, ?Frame $frame): void
    {
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                null,
                $frame->vmContext,
                $frame
            );
        } else {
            $ctx->errors->triggerError($message, ErrorReporter::E_WARNING, null, $ctx);
        }
    }
}
