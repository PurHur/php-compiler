<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * SAX-shaped struct builder for xml_parse_into_struct() (#3494).
 *
 * php-src: ext/xml/xml.c — xml_startElementHandler, xml_endElementHandler, xml_characterDataHandler
 */
final class VmXmlStructBuilder
{
    private int $level = 0;

    private bool $lastWasOpen = false;

    private ?int $currentTagIndex = null;

    private HashTable $values;

    private HashTable $index;

    public function __construct()
    {
        $this->values = new HashTable();
        $this->index = new HashTable();
    }

    /** @return array{values: HashTable, index: HashTable} */
    public function result(): array
    {
        return [
            'values' => $this->values,
            'index' => $this->index,
        ];
    }

    public static function build(string $data): self
    {
        $builder = new self();
        $trimmed = trim($data);
        $builder->structParseElementAt($trimmed, 0);

        return $builder;
    }

    private function structParseElementAt(string $data, int $pos): int
    {
        $pos = self::skipWhitespace($data, $pos);
        if ($pos >= \strlen($data) || '<' !== $data[$pos]) {
            throw new \LogicException('xml_parse_into_struct(): expected element start');
        }

        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?(\/?)>/s', $data, $open, 0, $pos)) {
            throw new \LogicException('xml_parse_into_struct(): malformed element');
        }

        $tag = $this->foldTag($open[1]);
        $attrSpec = $open[2] ?? '';
        $selfClose = isset($open[3]) && '/' === $open[3];
        $attrs = self::parseAttributes($attrSpec);
        $contentStart = $pos + \strlen($open[0]);

        ++$this->level;
        $this->onStartElement($tag, $attrs);

        if ($selfClose) {
            $this->onEndElement($tag);

            return $contentStart;
        }

        $end = VmXml::findElementEndForStruct($data, $pos);
        if (null === $end) {
            throw new \LogicException('xml_parse_into_struct(): unclosed element');
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
                    $this->onCharacterData($text);
                }
                $scan = $textEnd;

                continue;
            }
            $cdata = VmXml::parseCdataSectionAt($data, $scan);
            if (null !== $cdata) {
                $this->onCharacterData($cdata['data']);
                $scan = $cdata['end'];

                continue;
            }
            $comment = VmXml::parseCommentAt($data, $scan);
            if (null !== $comment) {
                $scan = $comment['end'];

                continue;
            }
            $scan = $this->structParseElementAt($data, $scan);
        }

        $this->onEndElement($tag);

        return $end;
    }

    private function onStartElement(string $tag, HashTable $attrs): void
    {
        $idx = $this->values->getNumElements();
        $entry = self::makeStructEntry($tag, 'open', $this->level, $attrs);
        $entryVar = new Variable();
        $entryVar->array($entry);
        $this->values->append($entryVar);
        $this->currentTagIndex = $idx;
        $this->lastWasOpen = true;
        $this->addToIndex($tag, $idx);
    }

    private function onEndElement(string $tag): void
    {
        if ($this->lastWasOpen) {
            $entry = null !== $this->currentTagIndex
                ? $this->values->findIndex($this->currentTagIndex)
                : null;
            if (null !== $entry) {
                $entry = $entry->resolveIndirect();
                if (Variable::TYPE_ARRAY === $entry->type) {
                    $entry->separateArrayForWrite();
                    $ht = $entry->toArray();
                    $typeSlot = $ht->find('type');
                    if (null !== $typeSlot) {
                        $typeSlot->string('complete');
                    }
                }
            }
        } else {
            $idx = $this->values->getNumElements();
            $entry = self::makeStructEntry($tag, 'close', $this->level);
            $entryVar = new Variable();
            $entryVar->array($entry);
            $this->values->append($entryVar);
            $this->addToIndex($tag, $idx);
        }

        $this->lastWasOpen = false;
        $this->currentTagIndex = null;
        --$this->level;
    }

    private function onCharacterData(string $text): void
    {
        if (!$this->lastWasOpen || null === $this->currentTagIndex) {
            return;
        }
        $entry = $this->values->findIndex($this->currentTagIndex);
        if (null === $entry) {
            return;
        }
        $entry = $entry->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $entry->type) {
            return;
        }
        $entry->separateArrayForWrite();
        $ht = $entry->toArray();
        $valueSlot = $ht->find('value');
        if (null !== $valueSlot) {
            $valueSlot->string($valueSlot->resolveIndirect()->toString().$text);

            return;
        }
        $valVar = new Variable();
        $valVar->string($text);
        $ht->add('value', $valVar);
    }

    private function addToIndex(string $tag, int $idx): void
    {
        $existing = $this->index->find($tag);
        if (null === $existing) {
            $arr = new HashTable();
            $v = new Variable();
            $v->int($idx);
            $arr->append($v);
            $entry = new Variable();
            $entry->array($arr);
            $this->index->add($tag, $entry);

            return;
        }
        $existing = $existing->resolveIndirect();
        if (Variable::TYPE_ARRAY === $existing->type) {
            $existing->separateArrayForWrite();
        }
        $arr = $existing->toArray();
        $v = new Variable();
        $v->int($idx);
        $arr->append($v);
    }

    private function foldTag(string $tag): string
    {
        return strtoupper($tag);
    }

    private static function makeStructEntry(
        string $tag,
        string $type,
        int $level,
        ?HashTable $attrs = null
    ): HashTable {
        $ht = new HashTable();
        $tagVar = new Variable();
        $tagVar->string($tag);
        $ht->add('tag', $tagVar);
        $typeVar = new Variable();
        $typeVar->string($type);
        $ht->add('type', $typeVar);
        $levelVar = new Variable();
        $levelVar->int($level);
        $ht->add('level', $levelVar);
        if (null !== $attrs && $attrs->getNumElements() > 0) {
            $attrsVar = new Variable();
            $attrsVar->array($attrs);
            $ht->add('attributes', $attrsVar);
        }

        return $ht;
    }

    private static function parseAttributes(string $attrSpec): HashTable
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
                $attrs->add(strtoupper($match[1]), $val);
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
