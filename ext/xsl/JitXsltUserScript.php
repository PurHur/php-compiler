<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\ext\standard\XslConstants;
use PHPLLVM\Value;

/**
 * User-script AOT: compile-time XSLTProcessor security/EXSLT methods via host ext/xsl (#20392).
 *
 * Tracks processors allocated in the user script so hasExsltSupport / setSecurityPrefs /
 * getSecurityPrefs can fold to constants matching VM/Zend. No runtime/*.c growth.
 */
final class JitXsltUserScript
{
    /** @var \SplObjectStorage<JITVariable, \XSLTProcessor>|null */
    private static ?\SplObjectStorage $processors = null;

    /** @var array<string, \XSLTProcessor> */
    private static array $processorsByToken = [];

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
