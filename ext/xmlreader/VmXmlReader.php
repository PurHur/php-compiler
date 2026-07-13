<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
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
        $contents = VmFsReadNative::read($uri);
        if (false === $contents) {
            self::warn($ctx, 'XMLReader::open(): Unable to open source data', $frame);

            return null;
        }

        return self::openFromString($ctx, $uri, $contents, $frame);
    }

    public static function openFromString(Context $ctx, string $uri, string $data, ?Frame $frame = null): ?ObjectEntry
    {
        $valid = VmXml::validateAndReport($ctx, $data, $frame);
        $events = [];
        if ($valid) {
            try {
                $events = self::tokenize($data);
            } catch (\LogicException) {
                $valid = false;
            }
        }

        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('XMLReader is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $state = new XmlReaderState();
        $state->uri = $uri;
        $state->valid = $valid;
        $state->events = $events;
        XmlReaderRegistry::attach($entry, $state);

        return $entry;
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
