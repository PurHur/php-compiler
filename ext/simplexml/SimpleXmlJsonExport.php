<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * json_encode() / (array) / get_object_vars() wire for SimpleXMLElement
 * (php-src ext/simplexml/sxe.c — sxe_object_cast_ex / get_properties_for; #18291, #21666).
 *
 * Zend encodes SimpleXML via the same property shape for JSON and array cast; nested
 * non-scalar children become live SimpleXMLElement objects under (array)/get_object_vars
 * and plain objects/arrays under json_encode().
 */
final class SimpleXmlJsonExport
{
    public static function handles(ObjectEntry $object): bool
    {
        return VmSimpleXml::CLASS_LC === strtolower($object->class->name)
            && SimpleXmlRegistry::has($object);
    }

    /**
     * Zend always json_encodes SimpleXMLElement as a JSON object (php_json_encoder object path).
     * PHP arrays with key 0 alone are lists and become JSON arrays — use stdClass (#22733, #22730).
     */
    public static function exportZendJsonWire(ObjectEntry $object): \stdClass
    {
        VmSimpleXml::requireElement($object, 'json_encode');
        if (SimpleXmlRegistry::isAttributeNodeView($object)) {
            // Live attr handle: {"0":"<current value>"} (php-src sxe.c; #22733, #22654).
            return self::toJsonObject(['0' => VmSimpleXml::textContent($object)]);
        }
        if (SimpleXmlRegistry::isAttributesView($object)) {
            $attrs = VmSimpleXml::attributesMap($object);

            return [] === $attrs ? new \stdClass() : self::toJsonObject(['@attributes' => $attrs]);
        }
        if (SimpleXmlRegistry::isChildrenView($object)) {
            return self::toJsonObject(self::childrenGroupedJson(SimpleXmlRegistry::state($object)));
        }
        if (SimpleXmlRegistry::isView($object)) {
            $elements = VmSimpleXml::viewElements($object);
            if ([] === $elements) {
                return new \stdClass();
            }
            if (1 === \count($elements)) {
                return self::toJsonObject(self::nodeToWireArray($elements[0]));
            }
            $out = [];
            foreach ($elements as $index => $element) {
                $out[(string) $index] = self::elementJsonValue($element);
            }

            return self::toJsonObject($out);
        }

        return self::toJsonObject(self::nodeToWireArray(SimpleXmlRegistry::state($object)));
    }

    /**
     * (array) cast / get_object_vars() — php-src sxe_object_cast_ex (#21666).
     */
    public static function exportZendArrayCast(ObjectEntry $object): Variable
    {
        VmSimpleXml::requireElement($object, '(array)');
        $class = $object->class;
        $docKey = SimpleXmlRegistry::documentKey($object);

        if (SimpleXmlRegistry::isAttributeNodeView($object)) {
            $ht = new HashTable();
            $text = new Variable();
            $text->string(VmSimpleXml::textContent($object));
            $ht->addIndex(0, $text);
            $result = new Variable();
            $result->array($ht);

            return $result;
        }
        if (SimpleXmlRegistry::isAttributesView($object)) {
            $attrs = VmSimpleXml::attributesMap($object);

            return self::attrsOnlyVariable($attrs);
        }
        if (SimpleXmlRegistry::isChildrenView($object)) {
            return self::childrenGroupedArrayCast(SimpleXmlRegistry::state($object), $class, $docKey);
        }
        if (SimpleXmlRegistry::isView($object)) {
            $elements = VmSimpleXml::viewElements($object);
            if ([] === $elements) {
                return self::emptyArrayVariable();
            }
            if (1 === \count($elements)) {
                return self::nodeToPropertiesVariable($elements[0], $class, $docKey);
            }

            return self::indexedElementValues($elements, $class, $docKey);
        }

        return self::nodeToPropertiesVariable(SimpleXmlRegistry::state($object), $class, $docKey);
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
        foreach (self::groupChildren($node) as $name => $children) {
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

    /**
     * Children() view JSON — child element map without parent attrs/text
     * (php-src sxe get_properties_for on children iterator).
     *
     * @return array<string, mixed>
     */
    private static function childrenGroupedJson(SimpleXmlNodeState $parent): array
    {
        $out = [];
        foreach (self::groupChildren($parent) as $name => $children) {
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
        if ([] === $node->children) {
            // php-src: text content wins even when attributes are present (#21666 leaf rule).
            if ('' !== $node->text) {
                return $node->text;
            }
            if ([] === $node->attributes) {
                return new \stdClass();
            }

            return self::toJsonObject(self::nodeToWireArray($node));
        }

        return self::toJsonObject(self::nodeToWireArray($node));
    }

    /**
     * @param array<array-key, mixed> $wire
     */
    private static function toJsonObject(array $wire): \stdClass
    {
        return (object) $wire;
    }

    private static function nodeToPropertiesVariable(
        SimpleXmlNodeState $node,
        ClassEntry $class,
        int $docKey
    ): Variable {
        $ht = new HashTable();
        if ([] !== $node->attributes) {
            $ht->add('@attributes', VmJson::import($node->attributes));
        }
        if ('' !== $node->text) {
            $text = new Variable();
            $text->string($node->text);
            $ht->addIndex(0, $text);
        }
        foreach (self::groupChildren($node) as $name => $children) {
            if (1 === \count($children)) {
                $ht->add($name, self::elementCastValue($children[0], $class, $docKey));
            } else {
                $ht->add($name, self::indexedElementValues($children, $class, $docKey));
            }
        }
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    private static function childrenGroupedArrayCast(
        SimpleXmlNodeState $parent,
        ClassEntry $class,
        int $docKey
    ): Variable {
        $ht = new HashTable();
        foreach (self::groupChildren($parent) as $name => $children) {
            if (1 === \count($children)) {
                $ht->add($name, self::elementCastValue($children[0], $class, $docKey));
            } else {
                $ht->add($name, self::indexedElementValues($children, $class, $docKey));
            }
        }
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    /**
     * @param list<SimpleXmlNodeState> $elements
     */
    private static function indexedElementValues(
        array $elements,
        ClassEntry $class,
        int $docKey
    ): Variable {
        $ht = new HashTable();
        foreach ($elements as $index => $element) {
            $ht->addIndex((int) $index, self::elementCastValue($element, $class, $docKey));
        }
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    private static function elementCastValue(
        SimpleXmlNodeState $node,
        ClassEntry $class,
        int $docKey
    ): Variable {
        if ([] === $node->children && '' !== $node->text) {
            // Text leaf (attrs ignored) — php-src sxe_prop_dim_read / cast.
            $var = new Variable();
            $var->string($node->text);

            return $var;
        }
        // Empty, attrs-only, or element children → live SimpleXMLElement.
        $var = new Variable();
        $var->object(VmSimpleXml::wrapNodeForExport($class, $node, $docKey));

        return $var;
    }

    /**
     * @param array<string, string> $attrs
     */
    private static function attrsOnlyVariable(array $attrs): Variable
    {
        if ([] === $attrs) {
            return self::emptyArrayVariable();
        }
        $ht = new HashTable();
        $ht->add('@attributes', VmJson::import($attrs));
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    private static function emptyArrayVariable(): Variable
    {
        $var = new Variable();
        $var->newArray();

        return $var;
    }

    /**
     * @return array<string, list<SimpleXmlNodeState>>
     */
    private static function groupChildren(SimpleXmlNodeState $node): array
    {
        /** @var array<string, list<SimpleXmlNodeState>> $groups */
        $groups = [];
        foreach ($node->children as $child) {
            $groups[$child->name][] = $child;
        }

        return $groups;
    }
}
