<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: xml_parser_create / xml_parser_create_ns / xml_parse /
 * xml_get_error_code / xml_parser_free / xml_parser_set_option / xml_parser_get_option /
 * xml_parse_into_struct / xml_error_string / xml_get_current_{line,column,byte}_* /
 * xml_set_element_handler / xml_set_character_data_handler
 * (#27293, #29318, #34377, #34378, #34383, #34407, #34487, #34515).
 *
 * Runs the existing PHP-in-PHP parser model ({@see VmXml}, {@see XmlParserSupport}) at
 * compile time when arguments are literals, then emits constant results / an allocated
 * XMLParser shell — same shape as {@see \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript}.
 * SAX Closures replay via Variable::$closureCall (ArrayReduce peer); NestedClosureInvoke
 * multi-candidate dispatch wrong-targets when Closures use() (#34515 / #34487). No runtime/*.c.
 */
final class JitXmlParserUserScript
{
    /** @var \SplObjectStorage<JITVariable, ObjectEntry>|null */
    private static ?\SplObjectStorage $parsers = null;

    /** @var array<string, ObjectEntry> */
    private static array $parsersByToken = [];

    private static ?ObjectEntry $lastParser = null;

    private static int $tokenSeq = 0;

    /** Last xml_get_error_code fold — so xml_error_string(xml_get_error_code($p)) can lower. */
    private static ?int $lastErrorCodeFold = null;

    /**
     * AOT Closure handlers keyed by parser object id (#34487).
     *
     * @var array<int, array{element_start: ?JITVariable, element_end: ?JITVariable, character_data: ?JITVariable}>
     */
    private static array $aotHandlers = [];

    public static function isUserScriptAot(): bool
    {
        return UserScriptAotEnv::isActive();
    }

    /**
     * xml_parser_create(?string $encoding = null) — allocate + track parser (#27293).
     */
    public static function tryCreate(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()) {
            return null;
        }
        if (\count($args) > 1) {
            return null;
        }
        if (isset($args[0]) && !self::isOptionalEncodingOk($args[0])) {
            return null;
        }

        $vmCtx = $context->runtime->vmContext;
        XmlParserSupport::registerClass($vmCtx);
        $parserVar = XmlParserSupport::createParser($vmCtx);
        $entry = $parserVar->toObject();

        return self::materializeParser($context, $entry);
    }

    /**
     * xml_parser_create_ns(?string $encoding = null, string $separator = ":") (#34407).
     *
     * php-src ext/xml/xml.c PHP_FUNCTION(xml_parser_create_ns): namespace-aware SAX;
     * expanded names are uri + separator + localname.
     */
    public static function tryCreateNs(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot()) {
            return null;
        }
        if (\count($args) > 2) {
            return null;
        }
        if (isset($args[0]) && !self::isOptionalEncodingOk($args[0])) {
            return null;
        }
        $separator = ':';
        if (isset($args[1])) {
            $sep = self::compileTimeSeparator($args[1]);
            if (null === $sep) {
                return null;
            }
            $separator = $sep;
        }

        $vmCtx = $context->runtime->vmContext;
        XmlParserSupport::registerClass($vmCtx);
        $parserVar = XmlParserSupport::createParser($vmCtx, true, $separator);
        $entry = $parserVar->toObject();

        return self::materializeParser($context, $entry);
    }

    /**
     * xml_set_element_handler(XMLParser $parser, $start, $end) — store Closures (#34487).
     *
     * php-src ext/xml/xml.c PHP_FUNCTION(xml_set_element_handler).
     */
    public static function trySetElementHandler(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 1 || \count($args) > 3) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }
        $start = self::optionalClosureHandler($args[1] ?? null);
        $end = self::optionalClosureHandler($args[2] ?? null);
        if (false === $start || false === $end) {
            return null;
        }
        $slot = self::$aotHandlers[$parser->id] ?? [
            'element_start' => null,
            'element_end' => null,
            'character_data' => null,
        ];
        $slot['element_start'] = $start;
        $slot['element_end'] = $end;
        self::$aotHandlers[$parser->id] = $slot;

        return self::boolValue($context, true);
    }

    /**
     * xml_set_character_data_handler(XMLParser $parser, $handler) (#34487).
     *
     * php-src ext/xml/xml.c PHP_FUNCTION(xml_set_character_data_handler).
     */
    public static function trySetCharacterDataHandler(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 1 || \count($args) > 2) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }
        $handler = self::optionalClosureHandler($args[1] ?? null);
        if (false === $handler) {
            return null;
        }
        $slot = self::$aotHandlers[$parser->id] ?? [
            'element_start' => null,
            'element_end' => null,
            'character_data' => null,
        ];
        $slot['character_data'] = $handler;
        self::$aotHandlers[$parser->id] = $slot;

        return self::boolValue($context, true);
    }

    /**
     * xml_parse(XMLParser $parser, string $data, bool $is_final = false) (#27293).
     *
     * When AOT Closures were registered via xml_set_*_handler, collect SAX events with
     * host Ext/xml (Zend event order) and emit {@see NestedClosureInvoke} calls (#34487).
     */
    public static function tryParse(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 2 || \count($args) > 3) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }
        $data = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $data || str_starts_with($data, '__phpc_xmlp_')) {
            return null;
        }
        $isFinal = false;
        if (isset($args[2])) {
            $flag = self::compileTimeBool($args[2]);
            if (null === $flag) {
                return null;
            }
            $isFinal = $flag;
        }

        $handlers = self::$aotHandlers[$parser->id] ?? null;
        if (null !== $handlers && self::hasAnyAotHandler($handlers)) {
            $replayed = self::emitSaxHandlerReplay($context, $parser, $args[0], $data, $isFinal, $handlers);
            if (null === $replayed) {
                return null;
            }

            return $replayed;
        }

        $status = VmXml::parse(
            $context->runtime->vmContext,
            $parser->id,
            $data,
            $isFinal,
            null,
            $parser
        );

        return self::intValue($context, $status);
    }

    /**
     * xml_get_error_code(XMLParser $parser): int (#27293).
     */
    public static function tryGetErrorCode(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 1 !== \count($args)) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }
        $code = VmXml::getErrorCode($parser->id);
        self::$lastErrorCodeFold = $code;

        return self::intValue($context, $code);
    }

    /**
     * xml_error_string(int $error_code): ?string (#34383).
     *
     * php-src XML_ErrorString() always returns a C string for defined codes; VM maps
     * unknown codes to "Unknown" ({@see VmXml::errorString}).
     */
    public static function tryErrorString(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 1 !== \count($args)) {
            return null;
        }
        $code = self::compileTimeOptionInt($context, $args[0]);
        if (null === $code && null !== $args[0]->compileTimeLong) {
            $code = (int) $args[0]->compileTimeLong;
        }
        if (
            null === $code
            && JITVariable::TYPE_VALUE === $args[0]->type
            && null !== self::$lastErrorCodeFold
        ) {
            $code = self::$lastErrorCodeFold;
        }
        if (null === $code) {
            return null;
        }

        return self::stringValue($context, VmXml::errorString($code));
    }

    /**
     * xml_get_current_line_number(XMLParser $parser): int (#34383).
     */
    public static function tryGetCurrentLineNumber(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 1 !== \count($args)) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }

        return self::intValue($context, VmXml::getCurrentLineNumber($parser->id));
    }

    /**
     * xml_get_current_column_number(XMLParser $parser): int (#34383).
     */
    public static function tryGetCurrentColumnNumber(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 1 !== \count($args)) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }

        return self::intValue($context, VmXml::getCurrentColumnNumber($parser->id));
    }

    /**
     * xml_get_current_byte_index(XMLParser $parser): int (#34383).
     */
    public static function tryGetCurrentByteIndex(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 1 !== \count($args)) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }

        return self::intValue($context, VmXml::getCurrentByteIndex($parser->id));
    }

    /**
     * xml_parser_free(XMLParser $parser): bool — no-op since PHP 8.0 (#22813, #29318).
     */
    public static function tryFree(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 1 !== \count($args)) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }

        return self::boolValue($context, VmXml::parserFree($parser->id));
    }

    /**
     * xml_parser_set_option(XMLParser $parser, int $option, string|int|bool $value): bool (#34377).
     */
    public static function trySetOption(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 3 !== \count($args)) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }
        $option = self::compileTimeOptionInt($context, $args[1]);
        if (null === $option) {
            return null;
        }
        $resolved = self::compileTimeOptionValue($args[2]);
        if (null === $resolved) {
            return null;
        }

        $ok = XmlParserHandlers::setOption($parser, $option, $resolved['value']);

        return self::boolValue($context, $ok);
    }

    /**
     * xml_parser_get_option(XMLParser $parser, int $option): string|int|bool (#34377).
     */
    public static function tryGetOption(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || 2 !== \count($args)) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }
        $option = self::compileTimeOptionInt($context, $args[1]);
        if (null === $option) {
            return null;
        }

        $value = XmlParserHandlers::getOption($parser, $option);
        if (\is_int($value)) {
            return self::intValue($context, $value);
        }
        if (\is_string($value)) {
            return self::stringValue($context, $value);
        }

        return self::boolValue($context, (bool) $value);
    }

    /**
     * xml_parse_into_struct(XMLParser $parser, string $data, &$values, &$index = null) (#34378).
     *
     * Compile-time SAX struct build via {@see VmXml::parseIntoStruct}; emit the resulting
     * HashTables into the by-ref slots. php-src: ext/xml/xml.c PHP_FUNCTION(xml_parse_into_struct).
     */
    public static function tryParseIntoStruct(Context $context, JITVariable ...$args): ?Value
    {
        if (!self::isUserScriptAot() || \count($args) < 3 || \count($args) > 4) {
            return null;
        }
        $parser = self::lookup($args[0]);
        if (null === $parser) {
            return null;
        }
        $data = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $data || str_starts_with($data, '__phpc_xmlp_')) {
            return null;
        }

        $parsed = VmXml::parseIntoStruct(
            $context->runtime->vmContext,
            $parser->id,
            $data
        );
        $valuesHt = HashTableHelper::variableFromVmHashTable($context, $parsed['values']);
        HashTableHelper::storeHashtableInArrayVariable($context, $args[2], $valuesHt->value);
        if (isset($args[3])) {
            $indexHt = HashTableHelper::variableFromVmHashTable($context, $parsed['index']);
            HashTableHelper::storeHashtableInArrayVariable($context, $args[3], $indexHt->value);
        }

        return self::intValue($context, $parsed['status']);
    }

    /**
     * @return null|JITVariable|false  null=cleared; JITVariable=ok; false=cannot lower
     */
    private static function optionalClosureHandler(?JITVariable $arg): null|JITVariable|false
    {
        if (null === $arg || JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return null;
        }
        if (null === $arg->closureCall) {
            return false;
        }

        return $arg;
    }

    /**
     * @param array{element_start: ?JITVariable, element_end: ?JITVariable, character_data: ?JITVariable} $handlers
     */
    private static function hasAnyAotHandler(array $handlers): bool
    {
        return null !== $handlers['element_start']
            || null !== $handlers['element_end']
            || null !== $handlers['character_data'];
    }

    /**
     * Invoke a compile-time SAX Closure with known Variable::$closureCall (#34515).
     *
     * Falls back to NestedClosureInvoke only when closureCall was stripped (should not
     * happen for handlers accepted by {@see optionalClosureHandler}).
     */
    private static function invokeSaxClosure(Context $context, JITVariable $handler, JITVariable ...$args): void
    {
        if (null !== $handler->closureCall) {
            $handler->closureCall->call($context, ...$args);

            return;
        }
        $invoke = new NestedClosureInvoke();
        $invoke->call($context, $handler, ...$args);
    }

    /**
     * @param array{element_start: ?JITVariable, element_end: ?JITVariable, character_data: ?JITVariable} $handlers
     */
    private static function emitSaxHandlerReplay(
        Context $context,
        ObjectEntry $parser,
        JITVariable $parserVar,
        string $data,
        bool $isFinal,
        array $handlers
    ): ?Value {
        $events = self::collectHostSaxEvents($parser, $data, $isFinal, $handlers);
        if (null === $events) {
            return null;
        }

        // Prefer Variable::$closureCall (ArrayReduceLlvm peer #24156). NestedClosureInvoke →
        // RuntimeIndirectClosureCall misfires when multiple Closures with use() share a module:
        // end events call the start body → ArgumentCountError (#34515 / re-#34487).
        NestedClosureInvokeLlvm::ensureLinked($context);
        foreach ($events['events'] as $ev) {
            $kind = $ev['kind'];
            if ('start' === $kind && null !== $handlers['element_start']) {
                self::invokeSaxClosure(
                    $context,
                    $handlers['element_start'],
                    $parserVar,
                    self::stringArg($context, $ev['name']),
                    self::attrsArg($context, $ev['attrs'])
                );
            } elseif ('end' === $kind && null !== $handlers['element_end']) {
                self::invokeSaxClosure(
                    $context,
                    $handlers['element_end'],
                    $parserVar,
                    self::stringArg($context, $ev['name'])
                );
            } elseif ('cdata' === $kind && null !== $handlers['character_data']) {
                self::invokeSaxClosure(
                    $context,
                    $handlers['character_data'],
                    $parserVar,
                    self::stringArg($context, $ev['data'])
                );
            }
        }

        // Advance VM parser cursor / error state like a real xml_parse (no ObjectEntry handlers).
        $status = VmXml::parse(
            $context->runtime->vmContext,
            $parser->id,
            $data,
            $isFinal,
            null,
            $parser
        );

        return self::intValue($context, $status);
    }

    /**
     * Host Ext/xml event capture — matches Zend SAX order / CASE_FOLDING (#34487).
     *
     * @param array{element_start: ?JITVariable, element_end: ?JITVariable, character_data: ?JITVariable} $handlers
     *
     * @return null|array{events: list<array<string, mixed>>}
     */
    private static function collectHostSaxEvents(
        ObjectEntry $parser,
        string $data,
        bool $isFinal,
        array $handlers
    ): ?array {
        if (!\function_exists('xml_parser_create')) {
            return null;
        }
        $state = VmXml::parserState($parser->id) ?? [];
        $caseFolding = 0 !== (int) ($state['options'][XmlConstants::XML_OPTION_CASE_FOLDING] ?? 1);
        $needStart = null !== $handlers['element_start'];
        $needEnd = null !== $handlers['element_end'];
        $needCdata = null !== $handlers['character_data'];

        $events = [];
        $host = \xml_parser_create();
        \xml_parser_set_option($host, \XML_OPTION_CASE_FOLDING, $caseFolding ? 1 : 0);
        if ($needStart || $needEnd) {
            \xml_set_element_handler(
                $host,
                $needStart
                    ? static function ($p, string $name, array $attrs) use (&$events): void {
                        $events[] = ['kind' => 'start', 'name' => $name, 'attrs' => $attrs];
                    }
                    : null,
                $needEnd
                    ? static function ($p, string $name) use (&$events): void {
                        $events[] = ['kind' => 'end', 'name' => $name];
                    }
                    : null
            );
        }
        if ($needCdata) {
            \xml_set_character_data_handler(
                $host,
                static function ($p, string $chunk) use (&$events): void {
                    $events[] = ['kind' => 'cdata', 'data' => $chunk];
                }
            );
        }
        \xml_parse($host, $data, $isFinal);
        \xml_parser_free($host);

        return ['events' => $events];
    }

    private static function stringArg(Context $context, string $str): JITVariable
    {
        $lit = $context->builder->load($context->constantStringFromString($str));
        $var = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $lit);
        $var->compileTimeString = $str;

        return $var;
    }

    /** @param array<string, string> $attrs */
    private static function attrsArg(Context $context, array $attrs): JITVariable
    {
        $ht = new HashTable();
        foreach ($attrs as $key => $value) {
            $val = new VmVariable();
            $val->string((string) $value);
            $ht->add((string) $key, $val);
        }

        return HashTableHelper::variableFromVmHashTable($context, $ht);
    }

    private static function isOptionalEncodingOk(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return true;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;

        return null !== $lit && !str_starts_with($lit, '__phpc_xmlp_');
    }

    /** Default ":" when omitted/null (php-src xml.stub.php separator = ":"). */
    private static function compileTimeSeparator(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return ':';
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
        if (null === $lit || str_starts_with($lit, '__phpc_xmlp_')) {
            return null;
        }

        return $lit;
    }

    /** @return ?bool null = dynamic / unknown */
    private static function compileTimeBool(JITVariable $arg): ?bool
    {
        if (null !== $arg->compileTimeLong) {
            return 0 !== (int) $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeConstantName) {
            $cn = strtolower($arg->compileTimeConstantName);
            if ('true' === $cn) {
                return true;
            }
            if ('false' === $cn) {
                return false;
            }
        }
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if (\is_object($const) && \method_exists($const, 'constInt')) {
                try {
                    return 0 !== (int) $const->constInt(false);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /** Resolve XML_OPTION_* / literal int for set/get_option (#34377). */
    private static function compileTimeOptionInt(Context $context, JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeConstantName && null !== $context->runtime->vmContext) {
            $fetched = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
            if (null !== $fetched && \PHPCompiler\VM\Variable::TYPE_INTEGER === $fetched->type) {
                return $fetched->toInt();
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }

        return null;
    }

    /**
     * Compile-time set_option $value — string|int|bool|null.
     *
     * @return null|array{value: string|int|bool|null}  null = dynamic (cannot lower)
     */
    private static function compileTimeOptionValue(JITVariable $arg): ?array
    {
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return ['value' => null];
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
        if (null !== $lit && !str_starts_with($lit, '__phpc_xmlp_')) {
            return ['value' => $lit];
        }
        if (null !== $arg->compileTimeLong) {
            return ['value' => (int) $arg->compileTimeLong];
        }
        if (null !== $arg->compileTimeConstantName) {
            $cn = strtolower($arg->compileTimeConstantName);
            if ('true' === $cn) {
                return ['value' => true];
            }
            if ('false' === $cn) {
                return ['value' => false];
            }
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if (\is_object($const) && \method_exists($const, 'constInt')) {
                try {
                    return ['value' => 0 !== (int) $const->constInt(false)];
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private static function materializeParser(Context $context, ObjectEntry $entry): Value
    {
        $classId = $context->type->object->lookup(XmlParserSupport::CLASS_NAME);
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        $receiver = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        self::store($receiver, $entry);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function store(JITVariable $receiver, ObjectEntry $entry): void
    {
        if (null === self::$parsers) {
            self::$parsers = new \SplObjectStorage();
        }
        self::$parsers[$receiver] = $entry;
        $token = '__phpc_xmlp_'.(++self::$tokenSeq);
        $receiver->compileTimeString = $token;
        self::$parsersByToken[$token] = $entry;
        self::$lastParser = $entry;
    }

    private static function lookup(JITVariable $receiver): ?ObjectEntry
    {
        if (null !== self::$parsers && isset(self::$parsers[$receiver])) {
            return self::$parsers[$receiver];
        }
        $token = $receiver->compileTimeString;
        if (null !== $token && isset(self::$parsersByToken[$token])) {
            return self::$parsersByToken[$token];
        }

        return self::$lastParser;
    }

    private static function intValue(Context $context, int $n): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong($context, $slot, $i64->constInt($n, true));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function boolValue(Context $context, bool $v): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt($v ? 1 : 0, false));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function stringValue(Context $context, string $str): Value
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
