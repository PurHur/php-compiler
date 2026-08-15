<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\ext\dom\JitDomLoadXMLUserScript;
use PHPCompiler\ext\standard\XslConstants;
use PHPLLVM\Value;

/**
 * User-script AOT: compile-time XSLTProcessor methods via host ext/xsl (#20392).
 *
 * Tracks processors allocated in the user script so hasExsltSupport / setSecurityPrefs /
 * getSecurityPrefs / setProfiling / importStylesheet / transformToXML can fold to constants
 * matching VM/Zend. No runtime/*.c growth.
 */
final class JitXsltUserScript
{
    /** @var \SplObjectStorage<JITVariable, \XSLTProcessor>|null */
    private static ?\SplObjectStorage $processors = null;

    /** @var array<string, \XSLTProcessor> */
    private static array $processorsByToken = [];

    /** @var array<int, string> spl_object_id(processor) → stylesheet XML imported at compile time (#27392). */
    private static array $stylesheetXmlByProcessor = [];

    private static ?\XSLTProcessor $lastProcessor = null;

    private static int $tokenSeq = 0;

    public static function isUserScriptAot(): bool
    {
        return UserScriptAotEnv::isActive();
    }

    public static function tryInit(Context $context, JITVariable $receiver): ?Value
    {
        if (!XsltHostBridge::available()) {
            return null;
        }
        $proc = XsltHostBridge::createProcessor();
        self::store($receiver, $proc);

        return self::nullValue($context);
    }

    public static function tryHasExsltSupport(Context $context, JITVariable ...$args): ?Value
    {
        // Exact user arity 0 — Zend ArgumentCountError (#30993; php-src ext/xsl/xsl.stub.php).
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'XSLTProcessor::hasExsltSupport() expects exactly 0 arguments, '.(\count($args) - 1).' given'
            );
        }
        $proc = self::requireProcessor($args[0] ?? null);
        if (null === $proc) {
            return null;
        }

        return self::boolValue($context, XsltHostBridge::hasExsltSupport($proc));
    }

    public static function trySetSecurityPrefs(Context $context, JITVariable ...$args): ?Value
    {
        $proc = self::requireProcessor($args[0] ?? null);
        if (null === $proc || !isset($args[1])) {
            return null;
        }
        $prefs = self::tryCompileTimeLong($context, $args[1]);
        if (null === $prefs) {
            return null;
        }
        $old = XsltHostBridge::setSecurityPrefs($proc, $prefs);

        return self::intValue($context, $old);
    }

    public static function tryGetSecurityPrefs(Context $context, JITVariable ...$args): ?Value
    {
        $proc = self::requireProcessor($args[0] ?? null);
        if (null === $proc) {
            return null;
        }

        return self::intValue($context, XsltHostBridge::getSecurityPrefs($proc));
    }

    /**
     * XSLTProcessor::importStylesheet(DOMDocument $stylesheet): bool — user-script AOT (#22367).
     *
     * Prefers the compile-time XML bound to the stylesheet document receiver (#27392);
     * falls back to the most recent loadXML() literal.
     */
    public static function tryImportStylesheet(Context $context, JITVariable ...$args): ?Value
    {
        $proc = self::requireProcessor($args[0] ?? null);
        if (null === $proc || !isset($args[1])) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::compileTimeXmlFor($args[1])
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === $xml) {
            return null;
        }
        $hostDoc = new \DOMDocument();
        // Suppress parse noise for intentional non-stylesheet fixtures (Zend still returns false).
        $prev = libxml_use_internal_errors(true);
        $loaded = @$hostDoc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return self::boolValue($context, false);
        }
        $ok = @XsltHostBridge::importStylesheet($proc, $hostDoc);
        if ($ok) {
            self::$stylesheetXmlByProcessor[spl_object_id($proc)] = $xml;
        }

        return self::boolValue($context, $ok);
    }

    /**
     * XSLTProcessor::transformToXML(DOMDocument $doc): string|false — user-script AOT (#27392).
     *
     * Runs the host transform at compile time against the stylesheet already imported into the
     * tracked processor and the source document's compile-time XML. Avoids ExternalMethod NULL.
     */
    public static function tryTransformToXml(Context $context, JITVariable ...$args): ?Value
    {
        $proc = self::requireProcessor($args[0] ?? null);
        if (null === $proc || !isset($args[1])) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::compileTimeXmlFor($args[1])
            ?? JitDomLoadXMLUserScript::compileTimeXmlExcluding(
                self::$stylesheetXmlByProcessor[spl_object_id($proc)] ?? null
            );
        if (null === $xml || '' === $xml) {
            return null;
        }
        $hostDoc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = @$hostDoc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return self::falseValue($context);
        }
        $result = @XsltHostBridge::transformToXml($proc, $hostDoc);
        if (false === $result) {
            return self::falseValue($context);
        }

        return self::stringValue($context, $result);
    }

    /**
     * XSLTProcessor::setProfiling(?string $filename) — user-script AOT (#22272).
     *
     * Folds null / compile-time string literals through the host bridge. When the path
     * arrives as a boxed TYPE_VALUE without compileTimeString, still returns true
     * (php-src RETURN_TRUE) so AOT links; VM covers full path side-effects.
     */
    public static function trySetProfiling(Context $context, JITVariable ...$args): ?Value
    {
        $proc = self::requireProcessor($args[0] ?? null);
        if (null === $proc || !isset($args[1])) {
            return null;
        }
        $filename = self::tryCompileTimeNullableString($args[1]);
        if (false !== $filename) {
            if (null !== $filename && str_contains($filename, "\0")) {
                throw new \ValueError(
                    'XSLTProcessor::setProfiling(): Argument #1 ($filename) must not contain any null bytes'
                );
            }
            XsltHostBridge::setProfiling($proc, $filename);
        }

        return self::boolValue($context, true);
    }

    /**
     * @return string|null|false null = PHP null; string = path; false = not compile-time
     */
    private static function tryCompileTimeNullableString(JITVariable $var): string|null|false
    {
        if (JITVariable::TYPE_NULL === $var->type || $var->isNullConstant) {
            return null;
        }
        $lit = JitStringArg::compileTimeLiteral($var);
        if (null !== $lit) {
            return $lit;
        }
        if (null !== $var->compileTimeString) {
            return $var->compileTimeString;
        }

        return false;
    }

    private static function tryCompileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (null !== $var->compileTimeLong) {
            return (int) $var->compileTimeLong;
        }
        // Named constants (XSL_SECPREF_*) load from LLVM globals — Context::constantFetch sets this.
        if (null !== $var->compileTimeConstantName) {
            $phpVar = $context->runtime->vmContext->constantFetch($var->compileTimeConstantName);
            if (null !== $phpVar && VMVariable::TYPE_INTEGER === $phpVar->type) {
                return $phpVar->toInt();
            }
            $known = XslConstants::registeredConstants();
            $name = $var->compileTimeConstantName;
            if (isset($known[$name]) && \is_int($known[$name])) {
                return $known[$name];
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
    }

    private static function requireProcessor(?JITVariable $receiver): ?\XSLTProcessor
    {
        if (null === $receiver) {
            return null;
        }
        $proc = self::lookup($receiver);
        if (null !== $proc) {
            return $proc;
        }
        if (!XsltHostBridge::available()) {
            return null;
        }
        $proc = XsltHostBridge::createProcessor();
        self::store($receiver, $proc);

        return $proc;
    }

    private static function store(JITVariable $receiver, \XSLTProcessor $proc): void
    {
        if (null === self::$processors) {
            self::$processors = new \SplObjectStorage();
        }
        self::$processors[$receiver] = $proc;
        $token = '__phpc_xslt_'.(++self::$tokenSeq);
        $receiver->compileTimeString = $token;
        self::$processorsByToken[$token] = $proc;
        self::$lastProcessor = $proc;
    }

    private static function lookup(JITVariable $receiver): ?\XSLTProcessor
    {
        if (null !== self::$processors && isset(self::$processors[$receiver])) {
            return self::$processors[$receiver];
        }
        $token = $receiver->compileTimeString;
        if (null !== $token && isset(self::$processorsByToken[$token])) {
            return self::$processorsByToken[$token];
        }

        return self::$lastProcessor;
    }

    private static function boolValue(Context $context, bool $ok): Value
    {
        // Native i1 — boxed __value__ bool via this Call path reads as false under user-script AOT (#20392).
        return $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false);
    }

    private static function falseValue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt(0, false), $i32)
        );

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

    private static function intValue(Context $context, int $n): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong($context, $slot, $i64->constInt($n, true));

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
