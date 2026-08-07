<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM;
use PHPCompiler\VM\CallableCheck;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectRegistry;
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

    /**
     * Strong roots for Closure / invokable handlers (#19343).
     *
     * Inline call-arg Closures lose ClosureState when {@see \PHPCompiler\VM\ObjectLifetime::releaseDirectObject}
     * + scope-slot null run after the set_* call returns (refcount skew vs stored Variable copies).
     * Pin the ObjectEntry and stash ClosureState for reattach — same pattern as SplIteratorSupport (#6138).
     *
     * @var array<string, Variable>
     */
    private static array $handlerPins = [];

    /** @var array<int, ClosureState> */
    private static array $closureStatePins = [];

    /** @return array<string, mixed> */
    public static function defaultParserState(): array
    {
        // Expat starts current line/column at 1 before any input (php-src ext/xml/xml.c; #25286).
        return [
            'errorCode' => 0,
            'line' => 1,
            'column' => 1,
            'byteIndex' => 0,
            // Accumulated feed for xml_parse(..., $is_final=false) chunks (php-src XML_Parse; #24647).
            'buffer' => '',
            'saxDispatched' => false,
            // Incremental SAX cursor (#24657) — bytes of buffer already scanned for handlers.
            'saxConsumed' => 0,
            'saxPendingCdata' => '',
            /** @var array<string, string> */
            'saxNsBindings' => ['' => ''],
            /** @var list<array{rawTag: string, tag: string, endMarkup: string, nsBindings: array<string, string>}> */
            'saxOpenStack' => [],
            // After is_final=true Expat rejects further XML_Parse (php-src; #24647).
            'finished' => false,
            // True while xml_parse() is on the stack (php-src xml_parser.isparsing; #28171).
            'isParsing' => false,
            'nsAware' => false,
            'nsSeparator' => ':',
            'options' => self::defaultOptionSlots(),
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

    public static function setHandler(ObjectEntry $parser, string $slot, ?Variable $handler): bool
    {
        $state = VmXml::parserState($parser->id);
        if (null === $state) {
            return false;
        }
        if (null !== $handler && Variable::TYPE_STRING === $handler->type && '' === $handler->toString()) {
            $handler = null;
        }
        self::unpinHandlerSlot($parser->id, $slot);
        if (null !== $handler) {
            self::pinHandlerSlot($parser->id, $slot, $handler);
        }
        $state['handlers'][$slot] = $handler;
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

    /**
     * Default XML_OPTION_* slots for a fresh parser (php-src ext/xml/xml.c; #28171).
     *
     * @return array<int, int|string>
     */
    public static function defaultOptionSlots(): array
    {
        $options = [
            XmlConstants::XML_OPTION_CASE_FOLDING => 1,
            XmlConstants::XML_OPTION_TARGET_ENCODING => 'UTF-8',
            XmlConstants::XML_OPTION_SKIP_TAGSTART => 0,
            XmlConstants::XML_OPTION_SKIP_WHITE => 0,
        ];
        // Default false for BC & DoS protection (php-src xml.c parser->parsehuge).
        if (\PHPCompiler\CompilerVersion::supportsXmlOptionParseHuge()) {
            $options[XmlConstants::XML_OPTION_PARSE_HUGE] = 0;
        }

        return $options;
    }

    public static function setOption(ObjectEntry $parser, int $option, mixed $value): bool
    {
        $state = VmXml::parserState($parser->id);
        if (null === $state) {
            return false;
        }
        if (!\array_key_exists($option, $state['options'])) {
            throw new \ValueError('xml_parser_set_option(): Argument #2 ($option) must be a XML_OPTION_* constant');
        }
        // php-src: Cannot change XML_OPTION_PARSE_HUGE while parsing (#28171).
        if (
            XmlConstants::XML_OPTION_PARSE_HUGE === $option
            && !empty($state['isParsing'])
        ) {
            throw new \Error('Cannot change option XML_OPTION_PARSE_HUGE while parsing');
        }
        if (
            XmlConstants::XML_OPTION_CASE_FOLDING === $option
            || XmlConstants::XML_OPTION_SKIP_WHITE === $option
            || XmlConstants::XML_OPTION_PARSE_HUGE === $option
        ) {
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
            throw new \ValueError('xml_parser_get_option(): Argument #2 ($option) must be a XML_OPTION_* constant');
        }
        $value = $state['options'][$option];
        // php-src RETURN_BOOL for PARSE_HUGE (#28171). CASE_FOLDING/SKIP_WHITE stay int for
        // existing VM callers (pre-#28171 shape); Zend also returns bool for those.
        if (XmlConstants::XML_OPTION_PARSE_HUGE === $option) {
            return (bool) $value;
        }

        return $value;
    }

    /** Whether XML_PARSE_HUGE is enabled for this parser (PROFILE≥8.4; #28171). */
    public static function parseHugeEnabled(ObjectEntry $parser): bool
    {
        $state = VmXml::parserState($parser->id);

        return null !== $state
            && 0 !== ($state['options'][XmlConstants::XML_OPTION_PARSE_HUGE] ?? 0);
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

    /** Drop SAX handler pins when the parser resource is freed (#19343). */
    public static function releaseParserPins(int $parserId): void
    {
        $prefix = $parserId.':';
        foreach (array_keys(self::$handlerPins) as $key) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $slot = self::$handlerPins[$key];
            $objectId = null;
            $resolved = $slot->resolveIndirect();
            if (Variable::TYPE_OBJECT === $resolved->type) {
                try {
                    $objectId = $resolved->toObject()->id;
                } catch (\LogicException) {
                }
            }
            $slot->null();
            unset(self::$handlerPins[$key]);
            if (null !== $objectId) {
                self::dropClosureStatePinIfUnused($objectId);
            }
        }
    }

    /**
     * Resolve a stored SAX handler to a call_user_func-compatible Variable.
     *
     * Accepts string function names (legacy), Closure / invokable objects, and
     * callable arrays (#19683, #19343; php-src ext/xml/xml.c).
     */
    public static function handlerCallback(ObjectEntry $parser, mixed $handler): ?Variable
    {
        if (null === $handler) {
            return null;
        }
        if ($handler instanceof Variable) {
            $handler = $handler->resolveIndirect();
            if (Variable::TYPE_NULL === $handler->type) {
                return null;
            }
            if (Variable::TYPE_STRING === $handler->type) {
                $handlerName = $handler->toString();
                if ('' === $handlerName) {
                    return null;
                }

                return self::stringHandlerCallback($parser, $handlerName);
            }
            if (Variable::TYPE_OBJECT === $handler->type) {
                self::ensureHandlerObjectAlive($handler->toObject());
            }

            // Closure / invokable object / callable array — pass through.
            return $handler;
        }
        if (!\is_string($handler) || '' === $handler) {
            return null;
        }

        return self::stringHandlerCallback($parser, $handler);
    }

    /**
     * Resolve a string SAX handler name (php-src ext/xml/xml.c xml_set_*_handler).
     *
     * PHP 8.4+ uses zend_parse {@code F!} before the legacy method-name {@code S} path, so a
     * global/builtin callable (e.g. {@code end}) wins over {@see xml_set_object()} method lookup
     * (#28502). Pre-8.4 keeps the historical object-method preference when an object is set.
     */
    private static function stringHandlerCallback(ObjectEntry $parser, string $handlerName): Variable
    {
        $fn = new Variable();
        $fn->string($handlerName);

        // PROFILE≥8.4: prefer global/callable string (OF!F! / OSF! / OF!S) over method names.
        if (CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            $vm = VM::running();
            if (null !== $vm && CallableCheck::isCallable($fn, $vm->context, null)) {
                return $fn;
            }
        }

        $state = VmXml::parserState($parser->id);
        $object = null !== $state ? ($state['handlerObject'] ?? null) : null;
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

        return $fn;
    }

    private static function objectVar(ObjectEntry $object): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    private static function pinHandlerSlot(int $parserId, string $slot, Variable $handler): void
    {
        $handler = $handler->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $handler->type) {
            return;
        }
        try {
            $object = $handler->toObject();
        } catch (\LogicException) {
            return;
        }
        $key = $parserId.':'.$slot;
        if (isset(self::$handlerPins[$key])) {
            self::$handlerPins[$key]->null();
        }
        $pin = new Variable();
        $pin->object($object);
        self::$handlerPins[$key] = $pin;
        if (null !== $object->closureState) {
            self::$closureStatePins[$object->id] = $object->closureState;
        }
    }

    private static function unpinHandlerSlot(int $parserId, string $slot): void
    {
        $key = $parserId.':'.$slot;
        if (!isset(self::$handlerPins[$key])) {
            return;
        }
        $pin = self::$handlerPins[$key];
        $objectId = null;
        $resolved = $pin->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type) {
            try {
                $objectId = $resolved->toObject()->id;
            } catch (\LogicException) {
            }
        }
        $pin->null();
        unset(self::$handlerPins[$key]);
        if (null !== $objectId) {
            self::dropClosureStatePinIfUnused($objectId);
        }
    }

    private static function dropClosureStatePinIfUnused(int $objectId): void
    {
        foreach (self::$handlerPins as $pin) {
            $resolved = $pin->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                continue;
            }
            try {
                if ($resolved->toObject()->id === $objectId) {
                    return;
                }
            } catch (\LogicException) {
            }
        }
        unset(self::$closureStatePins[$objectId]);
    }

    /**
     * Reattach ClosureState cleared by premature ObjectLifetime teardown (#19343, #6138).
     */
    private static function ensureHandlerObjectAlive(ObjectEntry $object): void
    {
        if (null === $object->closureState && isset(self::$closureStatePins[$object->id])) {
            $object->closureState = self::$closureStatePins[$object->id];
        }
        if (!ObjectRegistry::isRegistered($object->id) && isset(self::$closureStatePins[$object->id])) {
            ObjectRegistry::register($object);
        }
    }
}
