<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Invoke registered SAX handlers during xml_parse() (#18203, #19683, php-src ext/xml/xml.c).
 *
 * Namespace-aware parsers (xml_parser_create_ns) expand element/attribute names as
 * uri + separator + localname and strip xmlns declarations from attribute bags.
 */
final class VmXmlSaxDispatcher
{
    public static function dispatch(
        Context $ctx,
        ObjectEntry $parser,
        string $data,
        ?Frame $frame = null
    ): void {
        $state = XmlParserHandlers::parserState($parser);
        if (null === $state) {
            return;
        }
        $handlers = $state['handlers'];
        if (null === $handlers[XmlParserHandlers::HANDLER_ELEMENT_START]
            && null === $handlers[XmlParserHandlers::HANDLER_ELEMENT_END]
            && null === $handlers[XmlParserHandlers::HANDLER_CHARACTER_DATA]) {
            return;
        }

        $dispatcher = new self($ctx, $parser, $state, $frame);
        $trimmed = trim($data);
        if ('' === $trimmed) {
            return;
        }
        $dispatcher->parseElementAt($trimmed, 0);
    }

    private Context $ctx;

    private ObjectEntry $parser;

    /** @var array<string, mixed> */
    private array $state;

    private ?Frame $frame;

    private Variable $parserVar;

    /** prefix => uri; empty-string key is the default namespace */
    /** @var array<string, string> */
    private array $nsBindings = ['' => ''];

    /** @param array<string, mixed> $state */
    private function __construct(Context $ctx, ObjectEntry $parser, array $state, ?Frame $frame)
    {
        $this->ctx = $ctx;
        $this->parser = $parser;
        $this->state = $state;
        $this->frame = $frame;
        $this->parserVar = new Variable(Variable::TYPE_OBJECT);
        $this->parserVar->object($parser);
    }

    private function parseElementAt(string $data, int $pos): int
    {
        $pos = self::skipWhitespace($data, $pos);
        if ($pos >= \strlen($data) || '<' !== $data[$pos]) {
            return $pos;
        }

        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?(\/?)>/s', $data, $open, 0, $pos)) {
            return $pos;
        }

        $rawTag = $open[1];
        $attrSpec = $open[2] ?? '';
        $selfClose = isset($open[3]) && '/' === $open[3];
        $savedBindings = $this->nsBindings;
        $attrs = $this->attributesForHandlers($attrSpec);
        $tag = $this->expandElementName($rawTag);
        $contentStart = $pos + \strlen($open[0]);

        $this->invokeElementStart($tag, $attrs);

        if ($selfClose) {
            $this->invokeElementEnd($tag);
            $this->nsBindings = $savedBindings;

            return $contentStart;
        }

        $end = VmXml::findElementEndForStruct($data, $pos);
        if (null === $end) {
            $this->nsBindings = $savedBindings;

            return $contentStart;
        }

        $innerEnd = $end - \strlen('</'.$open[1].'>');
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
                    $this->invokeCharacterData($text);
                }
                $scan = $textEnd;

                continue;
            }
            $cdata = VmXml::parseCdataSectionAt($data, $scan);
            if (null !== $cdata) {
                $this->invokeCharacterData($cdata['data']);
                $scan = $cdata['end'];

                continue;
            }
            $comment = VmXml::parseCommentAt($data, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $scan = $this->parseElementAt($data, $scan);
        }

        $this->invokeElementEnd($tag);
        $this->nsBindings = $savedBindings;

        return $end;
    }

    private function invokeElementStart(string $tag, HashTable $attrs): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_ELEMENT_START] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null === $callback) {
            return;
        }
        $nameVar = new Variable();
        $nameVar->string($tag);
        $attrsVar = new Variable();
        $attrsVar->array($attrs);
        VmCallable::invoke($this->ctx, $callback, $this->parserVar, $nameVar, $attrsVar);
    }

    private function invokeElementEnd(string $tag): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_ELEMENT_END] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null === $callback) {
            return;
        }
        $nameVar = new Variable();
        $nameVar->string($tag);
        VmCallable::invoke($this->ctx, $callback, $this->parserVar, $nameVar);
    }

    private function invokeCharacterData(string $text): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_CHARACTER_DATA] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, $handler);
        if (null === $callback) {
            return;
        }
        $dataVar = new Variable();
        $dataVar->string($text);
        VmCallable::invoke($this->ctx, $callback, $this->parserVar, $dataVar);
    }

    private function nsAware(): bool
    {
        return !empty($this->state['nsAware']);
    }

    private function nsSeparator(): string
    {
        return (string) ($this->state['nsSeparator'] ?? ':');
    }

    private function foldTag(string $tag): string
    {
        return $this->caseFolding() ? strtoupper($tag) : $tag;
    }

    private function caseFolding(): bool
    {
        return 0 !== ($this->state['options'][XmlConstants::XML_OPTION_CASE_FOLDING] ?? 1);
    }

    /**
     * Parse attributes, apply xmlns bindings for this element, expand names when NS-aware,
     * and omit xmlns declarations from the handler attribute bag (expat NS mode).
     */
    private function attributesForHandlers(string $attrSpec): HashTable
    {
        $parsed = self::parseAttributePairs($attrSpec);
        if ($this->nsAware()) {
            foreach ($parsed as $name => $value) {
                if ('xmlns' === $name) {
                    $this->nsBindings[''] = $value;
                } elseif (str_starts_with($name, 'xmlns:')) {
                    $prefix = substr($name, 6);
                    $this->nsBindings[$prefix] = $value;
                }
            }
        }

        $attrs = new HashTable();
        $fold = $this->caseFolding();
        foreach ($parsed as $name => $value) {
            if ($this->nsAware() && ('xmlns' === $name || str_starts_with($name, 'xmlns:'))) {
                continue;
            }
            $expanded = $this->nsAware() ? $this->expandAttributeName($name) : $name;
            $outName = $fold ? strtoupper($expanded) : $expanded;
            $val = new Variable();
            $val->string($value);
            $attrs->add($outName, $val);
        }

        return $attrs;
    }

    private function expandElementName(string $rawTag): string
    {
        if (!$this->nsAware()) {
            return $this->foldTag($rawTag);
        }
        $expanded = $this->expandQName($rawTag, true);

        return $this->foldTag($expanded);
    }

    private function expandAttributeName(string $rawName): string
    {
        return $this->expandQName($rawName, false);
    }

    /**
     * Expand a QName to uri+sep+local (or local when unbound / no URI).
     * Element names use the default namespace; attribute names do not (#19683 / expat).
     */
    private function expandQName(string $qname, bool $isElement): string
    {
        $colon = strpos($qname, ':');
        if (false !== $colon && 0 !== $colon) {
            $prefix = substr($qname, 0, $colon);
            $local = substr($qname, $colon + 1);
            $uri = $this->nsBindings[$prefix] ?? '';
            if ('' !== $uri) {
                return $uri.$this->nsSeparator().$local;
            }

            return $qname;
        }
        if ($isElement) {
            $uri = $this->nsBindings[''] ?? '';
            if ('' !== $uri) {
                return $uri.$this->nsSeparator().$qname;
            }
        }

        return $qname;
    }

    /** @return array<string, string> */
    private static function parseAttributePairs(string $attrSpec): array
    {
        $pairs = [];
        if ('' === trim($attrSpec)) {
            return $pairs;
        }
        if (preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/s', $attrSpec, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = '' !== ($match[2] ?? '') ? $match[2] : ('' !== ($match[3] ?? '') ? $match[3] : ($match[4] ?? ''));
                $pairs[$match[1]] = $value;
            }
        }

        return $pairs;
    }

    private static function skipWhitespace(string $data, int $pos): int
    {
        $len = \strlen($data);
        while ($pos < $len && ctype_space($data[$pos])) {
            ++$pos;
        }

        return $pos;
    }
}
