<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ObjectEntry;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: xml_parser_create / xml_parse / xml_get_error_code /
 * xml_parser_free / xml_parser_set_option / xml_parser_get_option / xml_parse_into_struct /
 * xml_error_string / xml_get_current_{line,column,byte}_*
 * (#27293, #29318, #34377, #34378, #34383).
 *
 * Runs the existing PHP-in-PHP parser model ({@see VmXml}, {@see XmlParserSupport}) at
 * compile time when arguments are literals, then emits constant results / an allocated
 * XMLParser shell — same shape as {@see \PHPCompiler\ext\simplexml\JitSimpleXmlUserScript}.
 * No runtime/*.c growth.
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
     * xml_parse(XMLParser $parser, string $data, bool $is_final = false) (#27293).
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

    private static function isOptionalEncodingOk(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return true;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;

        return null !== $lit && !str_starts_with($lit, '__phpc_xmlp_');
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
