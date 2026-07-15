<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * WDDX serialize/deserialize (php-src ext/wddx/wddx.c; #6327).
 *
 * PHP-in-PHP XML packet generation — host DOM only on VM execute path.
 */
final class VmWddx
{
    /** @var array<int, true> */
    private static array $serializeSeen = [];

    public static function serializeValue(Variable $value, ?string $comment = null): string
    {
        return self::packet(self::serializeVar($value->resolveIndirect(), null), $comment);
    }

    /**
     * @param list<array{0: string, 1: Variable}> $namedVars
     */
    public static function serializeNamedVars(array $namedVars, ?string $comment = null): string
    {
        self::$serializeSeen = [];
        $body = '<struct>';
        foreach ($namedVars as [$name, $value]) {
            $body .= self::serializeVar($value->resolveIndirect(), $name);
        }
        $body .= '</struct>';

        return self::packet($body, $comment);
    }

    /**
     * @return mixed|false
     */
    public static function deserialize(string $packet)
    {
        $packet = trim($packet);
        if ('' === $packet) {
            return false;
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($packet);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return false;
        }

        $data = self::firstChildElement($doc->documentElement, 'data');
        if (null === $data) {
            return false;
        }

        $child = self::firstElementChild($data);
        if (null === $child) {
            return false;
        }

        try {
            $decoded = self::decodeNode($child);
        } catch (\Throwable) {
            return false;
        }

        return $decoded;
    }

    private static function packet(string $body, ?string $comment): string
    {
        self::$serializeSeen = [];
        $out = "<wddxPacket version='1.0'>";
        if (null !== $comment && '' !== $comment) {
            $out .= '<header><comment>'.self::escapeHtml($comment).'</comment></header>';
        } else {
            $out .= '<header/>';
        }

        return $out.'<data>'.$body.'</data></wddxPacket>';
    }

    private static function serializeVar(Variable $value, ?string $name): string
    {
        $out = '';
        if (null !== $name) {
            $out .= "<var name='".self::escapeAttr($name)."'>";
        }

        switch ($value->type) {
            case Variable::TYPE_NULL:
                $out .= '<null/>';
                break;
            case Variable::TYPE_BOOLEAN:
                $out .= $value->toBool(null)
                    ? "<boolean value='true'/>"
                    : "<boolean value='false'/>";
                break;
            case Variable::TYPE_INTEGER:
            case Variable::TYPE_FLOAT:
                $out .= '<number>'.self::formatNumber($value).'</number>';
                break;
            case Variable::TYPE_STRING:
                $out .= '<string>'.self::escapeHtml($value->toString(null)).'</string>';
                break;
            case Variable::TYPE_ARRAY:
                $out .= self::serializeArray($value->toArray());
                break;
            case Variable::TYPE_OBJECT:
                $out .= self::serializeObject($value->toObject());
                break;
            default:
                throw new \Error('Cannot serialize WDDX value of type '.$value->type);
        }

        if (null !== $name) {
            $out .= '</var>';
        }

        return $out;
    }

    private static function serializeArray(HashTable $table): string
    {
        $oid = spl_object_id($table);
        if (isset(self::$serializeSeen[$oid])) {
            throw new \Error("WDDX doesn't support circular references");
        }
        self::$serializeSeen[$oid] = true;

        $pairs = iterator_to_array($table->iterateKeyed(true), false);
        if (!$table->isPackedList()) {
            $out = '<struct>';
            foreach ($pairs as [$key, $element]) {
                $out .= self::serializeVar($element->resolveIndirect(), self::keyToString($key->resolveIndirect()));
            }

            return $out.'</struct>';
        }

        $out = '<array length="'.\count($pairs).'">';
        foreach ($pairs as [, $element]) {
            $out .= self::serializeVar($element->resolveIndirect(), null);
        }

        return $out.'</array>';
    }

    private static function serializeObject(ObjectEntry $object): string
    {
        $oid = spl_object_id($object);
        if (isset(self::$serializeSeen[$oid])) {
            throw new \Error("WDDX doesn't support circular references");
        }
        self::$serializeSeen[$oid] = true;

        $out = '<struct>';
        foreach ($object->propertiesWithNames() as $name => $element) {
            $out .= self::serializeVar($element->resolveIndirect(), $name);
        }

        return $out.'</struct>';
    }

    private static function decodeNode(\DOMElement $node): mixed
    {
        $tag = strtolower($node->localName ?? $node->nodeName);

        return match ($tag) {
            'string' => html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'number' => self::parseNumber(trim($node->textContent ?? '0')),
            'boolean' => self::parseBoolean($node),
            'null' => null,
            'array' => self::decodeArray($node),
            'struct' => self::decodeStruct($node),
            default => null,
        };
    }

    /**
     * @return list<mixed>
     */
    private static function decodeArray(\DOMElement $arrayNode): array
    {
        $out = [];
        foreach ($arrayNode->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $out[] = self::decodeNode($child);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStruct(\DOMElement $structNode): array
    {
        $out = [];
        foreach ($structNode->childNodes as $child) {
            if (!$child instanceof \DOMElement || 'var' !== strtolower($child->localName ?? $child->nodeName)) {
                continue;
            }
            $name = $child->getAttribute('name');
            if ('' === $name) {
                continue;
            }
            $valueNode = self::firstElementChild($child);
            $out[$name] = null === $valueNode ? null : self::decodeNode($valueNode);
        }

        return $out;
    }

    private static function parseBoolean(\DOMElement $node): bool
    {
        if ($node->hasAttribute('value')) {
            $raw = strtolower(trim($node->getAttribute('value')));

            return 'true' === $raw || '1' === $raw;
        }
        $raw = strtolower(trim($node->textContent ?? ''));

        return 'true' === $raw || '1' === $raw;
    }

    private static function parseNumber(string $raw): int|float
    {
        if (str_contains($raw, '.') || str_contains($raw, 'e') || str_contains($raw, 'E')) {
            return (float) $raw;
        }

        return (int) $raw;
    }

    private static function keyToString(Variable $key): string
    {
        return match ($key->type) {
            Variable::TYPE_STRING => $key->toString(null),
            Variable::TYPE_INTEGER => (string) $key->toInt(null),
            default => $key->toString(null),
        };
    }

    private static function formatNumber(Variable $value): string
    {
        if (Variable::TYPE_INTEGER === $value->type) {
            return (string) $value->toInt(null);
        }
        $raw = rtrim(rtrim(sprintf('%.17F', $value->toFloat(null)), '0'), '.');

        return str_replace(',', '.', $raw);
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function firstElementChild(\DOMElement $node): ?\DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return $child;
            }
        }

        return null;
    }

    private static function firstChildElement(?\DOMElement $node, string $name): ?\DOMElement
    {
        if (null === $node) {
            return null;
        }
        $want = strtolower($name);
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $want === strtolower($child->localName ?? $child->nodeName)) {
                return $child;
            }
        }

        return null;
    }
}
