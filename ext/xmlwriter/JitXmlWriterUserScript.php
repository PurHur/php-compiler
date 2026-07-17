<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: compile-time XMLWriter via host ext/xmlwriter (#19551).
 *
 * Tracks writers constructed in the user script so method calls with compile-time
 * literal args can be applied on the host and results (bools/strings) emitted as constants.
 */
final class JitXmlWriterUserScript
{
    /** @var \SplObjectStorage<JITVariable, \XMLWriter>|null */
    private static ?\SplObjectStorage $writers = null;

    /** @var array<string, \XMLWriter> */
    private static array $writersByToken = [];

    private static ?\XMLWriter $lastWriter = null;

    private static int $tokenSeq = 0;

    public static function isUserScriptAot(): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $userScript || 'true' === strtolower((string) $userScript);
    }

    public static function tryInit(Context $context, JITVariable $receiver): ?Value
    {
        if (!\extension_loaded('xmlwriter') || !\class_exists(\XMLWriter::class, false)) {
            return null;
        }
        $writer = new \XMLWriter();
        self::store($receiver, $writer);

        return self::nullValue($context);
    }

    public static function tryOpenMemory(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->openMemory();

        return self::boolValue($context, $ok);
    }

    public static function tryStartDocument(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $version = '1.0';
        if (isset($args[1])) {
            if (JITVariable::TYPE_NULL === $args[1]->type) {
                $version = '1.0';
            } else {
                $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
                if (null === $lit || str_starts_with($lit, '__phpc_xw_')) {
                    return null;
                }
                $version = $lit;
            }
        }
        $encoding = null;
        if (isset($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $encoding = null;
            } else {
                $encoding = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
                if (null === $encoding || str_starts_with($encoding, '__phpc_xw_')) {
                    return null;
                }
            }
        }
        $ok = null === $encoding
            ? $writer->startDocument($version)
            : $writer->startDocument($version, $encoding);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->startElement($name);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryWriteAttribute(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1], $args[2])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $value = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')
            || null === $value || str_starts_with($value, '__phpc_xw_')
        ) {
            return null;
        }
        $ok = $writer->writeAttribute($name, $value);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartAttribute(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->startAttribute($name);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndAttribute(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endAttribute();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryText(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $content = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $content || str_starts_with($content, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->text($content);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartCData(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->startCData();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndCData(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endCData();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryStartPI(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $target = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $target || str_starts_with($target, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->startPI($target);

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndPI(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endPI();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryFullEndElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->fullEndElement();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endElement();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryEndDocument(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $ok = $writer->endDocument();

        return self::boolValue($context, (bool) $ok);
    }

    public static function tryOutputMemory(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $flush = self::compileTimeEmptyFlag($args[1] ?? null);
        if (null === $flush) {
            return null;
        }
        $out = $writer->outputMemory($flush);

        return self::stringValue($context, (string) $out);
    }

    public static function tryFlush(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer) {
            return null;
        }
        $empty = self::compileTimeEmptyFlag($args[1] ?? null);
        if (null === $empty) {
            return null;
        }
        $out = $writer->flush($empty);
        if (\is_string($out)) {
            return self::stringValue($context, $out);
        }

        return self::intValue($context, (int) $out);
    }

    /**
     * Procedural xmlwriter_* folding for user-script AOT (#19514).
     *
     * open_memory / open_uri create a host writer tracked via lastWriter so
     * subsequent procedural calls (writer as arg0) fold like OOP methods.
     */
    public static function tryProcedural(Context $context, string $function, JITVariable ...$args): ?Value
    {
        if (!\extension_loaded('xmlwriter') || !\class_exists(\XMLWriter::class, false)) {
            return null;
        }
        $fn = strtolower($function);

        return match ($fn) {
            'xmlwriter_open_memory' => self::tryProceduralOpenMemory($context),
            'xmlwriter_open_uri' => self::tryProceduralOpenUri($context, ...$args),
            'xmlwriter_set_indent' => self::tryProceduralSetIndent($context, ...$args),
            'xmlwriter_set_indent_string' => self::tryProceduralSetIndentString($context, ...$args),
            'xmlwriter_start_document' => self::tryStartDocument($context, ...$args),
            'xmlwriter_end_document' => self::tryEndDocument($context, ...$args),
            'xmlwriter_start_element' => self::tryStartElement($context, ...$args),
            'xmlwriter_end_element' => self::tryEndElement($context, ...$args),
            'xmlwriter_full_end_element' => self::tryFullEndElement($context, ...$args),
            'xmlwriter_write_attribute' => self::tryWriteAttribute($context, ...$args),
            'xmlwriter_start_attribute' => self::tryStartAttribute($context, ...$args),
            'xmlwriter_end_attribute' => self::tryEndAttribute($context, ...$args),
            'xmlwriter_write_element' => self::tryProceduralWriteElement($context, ...$args),
            'xmlwriter_write_cdata' => self::tryProceduralWriteCData($context, ...$args),
            'xmlwriter_write_comment' => self::tryProceduralWriteComment($context, ...$args),
            'xmlwriter_text' => self::tryText($context, ...$args),
            'xmlwriter_output_memory' => self::tryOutputMemory($context, ...$args),
            'xmlwriter_flush' => self::tryFlush($context, ...$args),
            default => null,
        };
    }

    private static function tryProceduralOpenMemory(Context $context): ?Value
    {
        $writer = new \XMLWriter();
        $ok = $writer->openMemory();
        if (!$ok) {
            return null;
        }
        // No destination JITVariable here — track via lastWriter for subsequent calls.
        self::$lastWriter = $writer;

        return self::nullValue($context);
    }

    private static function tryProceduralOpenUri(Context $context, JITVariable ...$args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        $uri = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $uri || str_starts_with($uri, '__phpc_xw_')) {
            return null;
        }
        $writer = new \XMLWriter();
        $ok = @$writer->openUri($uri);
        if (!$ok) {
            return self::boolValue($context, false);
        }
        self::$lastWriter = $writer;

        return self::nullValue($context);
    }

    private static function tryProceduralSetIndent(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $enable = self::compileTimeEmptyFlag($args[1]);
        if (null === $enable) {
            return null;
        }
        $ok = $writer->setIndent($enable);

        return self::boolValue($context, (bool) $ok);
    }

    private static function tryProceduralSetIndentString(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $indentation = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $indentation || str_starts_with($indentation, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->setIndentString($indentation);

        return self::boolValue($context, (bool) $ok);
    }

    private static function tryProceduralWriteElement(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name || str_starts_with($name, '__phpc_xw_')) {
            return null;
        }
        $content = null;
        if (isset($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $content = null;
            } else {
                $content = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
                if (null === $content || str_starts_with($content, '__phpc_xw_')) {
                    return null;
                }
            }
            $ok = $writer->writeElement($name, $content);
        } else {
            $ok = $writer->writeElement($name);
        }

        return self::boolValue($context, (bool) $ok);
    }

    private static function tryProceduralWriteCData(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $content = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $content || str_starts_with($content, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->writeCData($content);

        return self::boolValue($context, (bool) $ok);
    }

    private static function tryProceduralWriteComment(Context $context, JITVariable ...$args): ?Value
    {
        $writer = self::requireWriter($args[0] ?? null);
        if (null === $writer || !isset($args[1])) {
            return null;
        }
        $content = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $content || str_starts_with($content, '__phpc_xw_')) {
            return null;
        }
        $ok = $writer->writeComment($content);

        return self::boolValue($context, (bool) $ok);
    }

    /** @return ?bool null = dynamic / unknown (cannot fold) */
    private static function compileTimeEmptyFlag(?JITVariable $arg): ?bool
    {
        if (null === $arg) {
            return true;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant)) {
            return true;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            // Constant int1: CallUnpackCompileTime uses constInt(false). Instruction-backed
            // bools (common for `true`/`false` temporaries) cannot be folded here.
            if (\is_object($const) && \method_exists($const, 'constInt')) {
                try {
                    return 0 !== (int) $const->constInt(false);
                } catch (\Throwable $e) {
                    return null;
                }
            }
            if (\is_object($const) && \method_exists($const, 'isConstant') && $const->isConstant()
                && \method_exists($const, 'getConstantValue')) {
                return 0 !== (int) $const->getConstantValue();
            }
        }

        return null;
    }

    private static function requireWriter(?JITVariable $receiver): ?\XMLWriter
    {
        if (null === $receiver) {
            return null;
        }
        $writer = self::lookup($receiver);
        if (null !== $writer) {
            return $writer;
        }
        if (!\extension_loaded('xmlwriter') || !\class_exists(\XMLWriter::class, false)) {
            return null;
        }
        // Lazy init when NEW did not run through tryInit (no-arg constructed object).
        $writer = new \XMLWriter();
        self::store($receiver, $writer);

        return $writer;
    }

    private static function store(JITVariable $receiver, \XMLWriter $writer): void
    {
        if (null === self::$writers) {
            self::$writers = new \SplObjectStorage();
        }
        self::$writers[$receiver] = $writer;
        $token = '__phpc_xw_'.(++self::$tokenSeq);
        $receiver->compileTimeString = $token;
        self::$writersByToken[$token] = $writer;
        self::$lastWriter = $writer;
    }

    private static function lookup(JITVariable $receiver): ?\XMLWriter
    {
        if (null !== self::$writers && isset(self::$writers[$receiver])) {
            return self::$writers[$receiver];
        }
        $token = $receiver->compileTimeString;
        if (null !== $token && isset(self::$writersByToken[$token])) {
            return self::$writersByToken[$token];
        }

        return self::$lastWriter;
    }

    private static function boolValue(Context $context, bool $ok): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt($ok ? 1 : 0, false));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function intValue(Context $context, int $n): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong($context, $slot, $i64->constInt($n, true));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function stringValue(Context $context, string $xml): Value
    {
        $str = $context->builder->load($context->constantStringFromString($xml));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function nullValue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
