<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\UnserializeObjectDecodeLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_unserialize via UnserializeJitHelper PHP (#9163, #20785, #27030, #33213).
 *
 * Owns `__compiler_unserialize` ABI module-locally: {@see getNamedFunction} first, then
 * {@see implementUnserializeBridge}. Do not re-add empty always-on shells in {@see Type} —
 * leftover decls mint unserialize.1 (#31894 / #32122 / #33213).
 *
 * O: wire materialize lives in {@see UnserializeObjectDecodeLlvm} (#20785 thin bridge).
 * php-src: ext/standard/var_unserializer.c
 */
final class StringUnserialize
{
    private const HELPER_PATH = '/ext/standard/UnserializeJitHelper.php';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeJitHelper::decode';

    private const DECODE_SESSION_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeJitHelper::decodeSession';

    private const UNSER_BRIDGE_ENTRY = 'unser_bridge_entry';

    private const SESSION_BRIDGE_ENTRY = 'session_unser_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DECODE_HELPER,
        self::DECODE_SESSION_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_unserialize',
        'phpc_session_decode_payload',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
        foreach (['null', 'bool', 'int', 'string', 'float', 'array', 'copyfrom', 'resolveindirect'] as $varMethod) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $varMethod);
        }
        foreach (['add', 'updateindex', 'append'] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($context, $htMethod);
        }
        UnserializeObjectDecodeLlvm::ensureNativeHtInternalProxies($context);

        $unserProbe = $context->module->getNamedFunction('__compiler_unserialize');
        $sessionProbe = $context->module->getNamedFunction('phpc_session_decode_payload');
        if (JitVmHelperLink::hasNamedBridgeEntry($unserProbe, self::UNSER_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($sessionProbe, self::SESSION_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }
        if (null !== $unserProbe && $unserProbe->countBasicBlocks() > 0
            && null !== $sessionProbe && $sessionProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }

        self::ensureRuntimeHelpers($context);
        self::implementUnserializeBridge($context);
        self::implementSessionDecodeBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20785'
        );
    }

    public static function ensureObjectHelpersCompiled(Context $context): void
    {
        UnserializeObjectDecodeLlvm::ensureObjectHelpersCompiled($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UnserializeJitHelper compile (#20785)');
        }

        return $fn;
    }

    public static function emitObjectDecodeRuntime(Context $context, Value $payloadString): Value
    {
        return UnserializeObjectDecodeLlvm::emitObjectDecodeRuntime($context, $payloadString);
    }

    private static function implementUnserializeBridge(Context $context): void
    {
        $abiName = '__compiler_unserialize';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::UNSER_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($valuePtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20785'
        );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::UNSER_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $payload = $fn->getParam(0);
        $helperFn = self::helperFunction($context, self::DECODE_HELPER);
        $payloadArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payload,
            $helperFn->getParam(0)->typeOf()
        );
        $raw = $context->builder->call($helperFn, $payloadArg);
        $long = JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64);
        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $outPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $long
        );
        $context->builder->returnValue($outPtr);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSessionDecodeBridge(Context $context): void
    {
        $abiName = 'phpc_session_decode_payload';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::SESSION_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock(self::SESSION_BRIDGE_ENTRY);
        $empty = $fn->appendBasicBlock('session_unser_empty');
        $decode = $fn->appendBasicBlock('session_unser_decode');

        $context->builder->positionAtEnd($entry);
        $body = $fn->getParam(0);
        $len = $fn->getParam(1);
        $nullBody = $context->builder->icmp(Builder::INT_EQ, $body, $i8p->constNull());
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $len, $sizeT->constInt(0, false));
        $bad = $context->builder->or($nullBody, $zeroLen);
        $context->builder->branchIf($bad, $empty, $decode);

        $context->builder->positionAtEnd($empty);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        $context->builder->positionAtEnd($decode);
        $payloadStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $body
        );
        $htRaw = $context->builder->call(
            self::helperFunction($context, self::DECODE_SESSION_HELPER),
            $payloadStr
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringUnserialize bridge (#20785)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
