<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * SAX handler + option slots on XMLParser objects (php-src ext/xml/xml.c; #18203).
 */
final class XmlParserHandlers
{
    public const HANDLER_ELEMENT_START = 'element_start';
    public const HANDLER_ELEMENT_END = 'element_end';
    public const HANDLER_CHARACTER_DATA = 'character_data';
    public const HANDLER_DEFAULT = 'default';
    public const HANDLER_PI = 'processing_instruction';
    public const HANDLER_UNPARSED_ENTITY = 'unparsed_entity_decl';
    public const HANDLER_NOTATION = 'notation_decl';
    public const HANDLER_EXTERNAL_ENTITY = 'external_entity_ref';
    public const HANDLER_START_NS = 'start_namespace_decl';
    public const HANDLER_END_NS = 'end_namespace_decl';

    /** @return array<string, mixed> */
    public static function defaultParserState(): array
    {
        return [
            'errorCode' => 0,
            'line' => 0,
            'column' => 0,
            'byteIndex' => 0,
            'options' => [
                XmlConstants::XML_OPTION_CASE_FOLDING => 1,
                XmlConstants::XML_OPTION_TARGET_ENCODING => 'UTF-8',
                XmlConstants::XML_OPTION_SKIP_TAGSTART => 0,
                XmlConstants::XML_OPTION_SKIP_WHITE => 0,
            ],
            'handlers' => [
                self::HANDLER_ELEMENT_START => null,
                self::HANDLER_ELEMENT_END => null,
                self::HANDLER_CHARACTER_DATA => null,
                self::HANDLER_DEFAULT => null,
                self::HANDLER_PI => null,
                self::HANDLER_UNPARSED_ENTITY => null,
                self::HANDLER_NOTATION => null,
                self::HANDLER_EXTERNAL_ENTITY => null,
                self::HANDLER_START_NS => null,
                self::HANDLER_END_NS => null,
            ],
            'handlerObject' => null,
        ];
    }

    public static function setHandler(ObjectEntry $parser, string $slot, ?string $handler): bool
    {
        $state = VmXml::parserState($parser->id);
        if (null === $state) {
            return false;
        }
        $state['handlers'][$slot] = ('' === $handler) ? null : $handler;
        VmXml::replaceParserState($parser->id, $state);

        return true;
    }

    public static function setObject(ObjectEntry $parser, ?ObjectEntry $object): bool
    {
        $state = VmXml::parserState($parser->id);
        if (null === $state) {
            return false;
        }
        $state['handlerObject'] = $object;
        VmXml::replaceParserState($parser->id, $state);

        return true;
    }

    public static function setOption(ObjectEntry $parser, int $option, mixed $value): bool
    {
        $state = VmXml::parserState($parser->id);
        if (null === $state) {
            return false;
        }
        if (!\array_key_exists($option, $state['options'])) {
            return false;
        }
        if (XmlConstants::XML_OPTION_CASE_FOLDING === $option) {
            $state['options'][$option] = (int) (bool) $value;
        } elseif (XmlConstants::XML_OPTION_TARGET_ENCODING === $option) {
            if (!\is_string($value)) {
                return false;
            }
            $state['options'][$option] = $value;
        } else {
            $state['options'][$option] = (int) $value;
        }
        VmXml::replaceParserState($parser->id, $state);

        return true;
    }

    public static function getOption(ObjectEntry $parser, int $option): mixed
    {
        $state = VmXml::parserState($parser->id);
        if (null === $state) {
            throw new \ValueError('xml_parser_get_option(): Argument #1 ($parser) must be a valid XML parser');
        }
        if (!\array_key_exists($option, $state['options'])) {
            return false;
        }

        return $state['options'][$option];
    }

    public static function caseFoldingEnabled(ObjectEntry $parser): bool
    {
        $state = VmXml::parserState($parser->id);

        return null !== $state && 0 !== ($state['options'][XmlConstants::XML_OPTION_CASE_FOLDING] ?? 1);
    }

    /** @return null|array<string, mixed> */
    public static function parserState(ObjectEntry $parser): ?array
    {
        return VmXml::parserState($parser->id);
    }

    public static function handlerCallback(ObjectEntry $parser, ?string $handlerName): ?Variable
    {
        if (null === $handlerName || '' === $handlerName) {
            return null;
        }
        $state = VmXml::parserState($parser->id);
        if (null === $state) {
            return null;
        }
        $object = $state['handlerObject'] ?? null;
        if ($object instanceof ObjectEntry) {
            $ht = new HashTable();
            $ht->append(self::objectVar($object));
            $method = new Variable();
            $method->string($handlerName);
            $ht->append($method);
            $cb = new Variable(Variable::TYPE_ARRAY);
            $cb->array($ht);

            return $cb;
        }
        $fn = new Variable();
        $fn->string($handlerName);

        return $fn;
    }

    private static function objectVar(ObjectEntry $object): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }
}
