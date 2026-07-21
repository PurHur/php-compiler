<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * SAX-shaped struct builder for xml_parse_into_struct() (#3494, #21631).
 *
 * php-src: ext/xml/xml.c — xml_startElementHandler, xml_endElementHandler, xml_characterDataHandler
 *
 * Namespace-aware parsers (xml_parser_create_ns) expand element/attribute names as
 * uri + separator + localname, omit xmlns* from attribute bags, and honor
 * XML_OPTION_CASE_FOLDING — same rules as {@see VmXmlSaxDispatcher}.
 */
final class VmXmlStructBuilder
{
    private int $level = 0;

    private bool $lastWasOpen = false;

    private ?int $currentTagIndex = null;

    private HashTable $values;

    private HashTable $index;

    private bool $nsAware;

    private string $nsSeparator;

    private bool $caseFolding;

    /** prefix => uri; empty-string key is the default namespace */
    /** @var array<string, string> */
    private array $nsBindings = ['' => ''];

    public function __construct(bool $nsAware = false, string $nsSeparator = ':', bool $caseFolding = true)
    {
        $this->values = new HashTable();
        $this->index = new HashTable();
        $this->nsAware = $nsAware;
        $this->nsSeparator = $nsSeparator;
        $this->caseFolding = $caseFolding;
    }

    /** @return array{values: HashTable, index: HashTable} */
    public function result(): array
    {
        return [
            'values' => $this->values,
            'index' => $this->index,
        ];
    }

    /**
     * @param array{nsAware?: bool, nsSeparator?: string, caseFolding?: bool} $options
     */
    public static function build(string $data, array $options = []): self
    {
        $builder = new self(
            !empty($options['nsAware']),
            (string) ($options['nsSeparator'] ?? ':'),
            !\array_key_exists('caseFolding', $options) || (bool) $options['caseFolding']
        );
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

        $rawTag = $open[1];
        $attrSpec = $open[2] ?? '';
        $selfClose = isset($open[3]) && '/' === $open[3];
        $savedBindings = $this->nsBindings;
        $this->applyNamespaceDeclarations($attrSpec);
        $tag = $this->expandElementName($rawTag);
        $attrs = $this->attributesForStruct($attrSpec);
        $contentStart = $pos + \strlen($open[0]);

        ++$this->level;
        $this->onStartElement($tag, $attrs);

        if ($selfClose) {
            $this->onEndElement($tag);
            $this->nsBindings = $savedBindings;

            return $contentStart;
        }

        $end = VmXml::findElementEndForStruct($data, $pos);
        if (null === $end) {
            $this->nsBindings = $savedBindings;
            throw new \LogicException('xml_parse_into_struct(): unclosed element');
        }

        $innerEnd = $end - \strlen('</'.$rawTag.'>');
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
        $this->nsBindings = $savedBindings;

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

    private function applyNamespaceDeclarations(string $attrSpec): void
    {
        if (!$this->nsAware) {
            return;
        }
        foreach (self::parseAttributePairs($attrSpec) as $name => $value) {
            if ('xmlns' === $name) {
                $this->nsBindings[''] = $value;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $this->nsBindings[substr($name, 6)] = $value;
            }
        }
    }

    private function attributesForStruct(string $attrSpec): HashTable
    {
        $attrs = new HashTable();
        foreach (self::parseAttributePairs($attrSpec) as $name => $value) {
            if ($this->nsAware && ('xmlns' === $name || str_starts_with($name, 'xmlns:'))) {
                continue;
            }
            $expanded = $this->nsAware ? $this->expandAttributeName($name) : $name;
            $outName = $this->foldName($expanded);
            $val = new Variable();
            $val->string($value);
            $attrs->add($outName, $val);
        }

        return $attrs;
    }

    private function expandElementName(string $rawTag): string
    {
        if (!$this->nsAware) {
            return $this->foldName($rawTag);
        }

        return $this->foldName($this->expandQName($rawTag, true));
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
                return $uri.$this->nsSeparator.$local;
            }

            return $qname;
        }
        if ($isElement) {
            $uri = $this->nsBindings[''] ?? '';
            if ('' !== $uri) {
                return $uri.$this->nsSeparator.$qname;
            }
        }

        return $qname;
    }

    private function foldName(string $name): string
    {
        return $this->caseFolding ? strtoupper($name) : $name;
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
