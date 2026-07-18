<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\VM\ObjectEntry;

/**
 * json_encode() wire for SimpleXMLElement (php-src ext/simplexml + ext/json; #18291).
 *
 * Zend encodes SimpleXML via the same shape as {@see (array)} cast on SimpleXMLElement.
 */
final class SimpleXmlJsonExport
{
    public static function handles(ObjectEntry $object): bool
    {
        return VmSimpleXml::CLASS_LC === strtolower($object->class->name)
            && SimpleXmlRegistry::has($object);
    }

    /**
     * @return array<string, mixed>
     */
    public static function exportZendJsonWire(ObjectEntry $object): array
    {
        VmSimpleXml::requireElement($object, 'json_encode');
        if (SimpleXmlRegistry::isAttributesView($object)) {
            $attrs = VmSimpleXml::attributesMap($object);

            return [] === $attrs ? [] : ['@attributes' => $attrs];
        }
        if (SimpleXmlRegistry::isView($object)) {
            $elements = SimpleXmlRegistry::view($object);
            if ([] === $elements) {
                return [];
            }
            if (1 === \count($elements)) {
                return self::nodeToWireArray($elements[0]);
            }
            $out = [];
            foreach ($elements as $index => $element) {
                $out[(string) $index] = self::elementJsonValue($element);
            }

            return $out;
        }

        return self::nodeToWireArray(SimpleXmlRegistry::state($object));
    }

    /**
     * @return array<string, mixed>
     */
    private static function nodeToWireArray(SimpleXmlNodeState $node): array
    {
        $out = [];
        if ([] !== $node->attributes) {
            $out['@attributes'] = $node->attributes;
        }
        if ('' !== $node->text) {
            $out['0'] = $node->text;
        }
        /** @var array<string, list<SimpleXmlNodeState>> $groups */
        $groups = [];
        foreach ($node->children as $child) {
            $groups[$child->name][] = $child;
        }
        foreach ($groups as $name => $children) {
            if (1 === \count($children)) {
                $out[$name] = self::elementJsonValue($children[0]);
            } else {
                $out[$name] = array_map(
                    static fn (SimpleXmlNodeState $child): mixed => self::elementJsonValue($child),
                    $children
                );
            }
        }

        return $out;
    }

    private static function elementJsonValue(SimpleXmlNodeState $node): mixed
    {
        if ([] === $node->children && [] === $node->attributes) {
            if ('' !== $node->text) {
                return $node->text;
            }

            return new \stdClass();
        }

        return self::nodeToWireArray($node);
    }
}
