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
 * Invoke registered SAX handlers during xml_parse() (#18203, php-src ext/xml/xml.c).
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

        $tag = $this->foldTag($open[1]);
        $attrSpec = $open[2] ?? '';
        $selfClose = isset($open[3]) && '/' === $open[3];
        $attrs = self::attributesArray($attrSpec, $this->caseFolding());
        $contentStart = $pos + \strlen($open[0]);

        $this->invokeElementStart($tag, $attrs);

        if ($selfClose) {
            $this->invokeElementEnd($tag);

            return $contentStart;
        }

        $end = VmXml::findElementEndForStruct($data, $pos);
        if (null === $end) {
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

        return $end;
    }

    private function invokeElementStart(string $tag, HashTable $attrs): void
    {
        $handler = $this->state['handlers'][XmlParserHandlers::HANDLER_ELEMENT_START] ?? null;
        $callback = XmlParserHandlers::handlerCallback($this->parser, \is_string($handler) ? $handler : null);
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
        $callback = XmlParserHandlers::handlerCallback($this->parser, \is_string($handler) ? $handler : null);
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
        $callback = XmlParserHandlers::handlerCallback($this->parser, \is_string($handler) ? $handler : null);
        if (null === $callback) {
            return;
        }
        $dataVar = new Variable();
        $dataVar->string($text);
        VmCallable::invoke($this->ctx, $callback, $this->parserVar, $dataVar);
    }

    private function foldTag(string $tag): string
    {
        return $this->caseFolding() ? strtoupper($tag) : $tag;
    }

    private function caseFolding(): bool
    {
        return 0 !== ($this->state['options'][XmlConstants::XML_OPTION_CASE_FOLDING] ?? 1);
    }

    private static function attributesArray(string $attrSpec, bool $fold): HashTable
    {
        $attrs = new HashTable();
        if ('' === trim($attrSpec)) {
            return $attrs;
        }
        if (preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/s', $attrSpec, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = '' !== ($match[2] ?? '') ? $match[2] : ('' !== ($match[3] ?? '') ? $match[3] : ($match[4] ?? ''));
                $val = new Variable();
                $val->string($value);
                $name = $fold ? strtoupper($match[1]) : $match[1];
                $attrs->add($name, $val);
            }
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
}
