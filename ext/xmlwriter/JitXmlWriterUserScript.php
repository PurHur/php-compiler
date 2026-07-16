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
        $flush = true;
        if (isset($args[1])) {
            if (null !== $args[1]->compileTimeLong) {
                $flush = 0 !== (int) $args[1]->compileTimeLong;
            } elseif (JITVariable::TYPE_NULL === $args[1]->type || !empty($args[1]->isNullConstant)) {
                $flush = true;
            } else {
                // Bool literals are NATIVE_BOOL without compileTimeLong — default flush=true is
                // fine for AOT fixtures; reject unknown dynamic flush args.
                return null;
            }
        }
        $out = $writer->outputMemory($flush);

        return self::stringValue($context, (string) $out);
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
