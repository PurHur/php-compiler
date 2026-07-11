<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_unserialize via UnserializeJitHelper PHP (#9163).
 *
 * JIT/normal modules and standalone AOT use compiled {@see UnserializeJitHelper} (#13312).
 * php-src: ext/standard/var_unserializer.c
 */
final class StringUnserialize
{
    private const HELPER_PATH = '/ext/standard/UnserializeJitHelper.php';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeJitHelper::decode';

    private const DECODE_SESSION_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeJitHelper::decodeSession';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DECODE_HELPER,
        self::DECODE_SESSION_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            self::ensureDeferredStubsForInventoryEmit($context);

            return;
        }
        self::implement($context);
    }

    /** Inventory argv emit: link unserialize ABI without nested UnserializeJitHelper JIT (#13322). */
    public static function ensureDeferredStubsForInventoryEmit(Context $context): void
    {
        if (!StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            return;
        }
        self::implementDeferredInventoryStubs($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_unserialize');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            self::ensureRuntimeHelpers($context);
            self::implementDeferredInventoryStubs($context);

            return;
        }

        self::ensureRuntimeHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementUnserializeBridge($context);
        self::implementSessionDecodeBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementUnserializeBridge(Context $context): void
    {
        $abiName = '__compiler_unserialize';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('unser_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $payload = $fn->getParam(0);
        $out = $fn->getParam(1);
        $decoded = $context->builder->call(
            self::helperFunction($context, self::DECODE_HELPER),
            $payload
        );
        JitValueBox::copyIntoPointer($context, $out, $decoded);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSessionDecodeBridge(Context $context): void
    {
        $abiName = 'phpc_session_decode_payload';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('session_unser_entry');
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
        $ht = $context->builder->call(
            self::helperFunction($context, self::DECODE_SESSION_HELPER),
            $payloadStr
        );
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UnserializeJitHelper compile (#9163)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'UnserializeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('UnserializeJitHelper.php parseAndCompile failed (#9163)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9163)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_unserialize', 'phpc_session_decode_payload'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringUnserialize bridge (#9163)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    /** No-op / empty hashtable — inventory emit only needs linkable ABI symbols (#13322). */
    private static function implementDeferredInventoryStubs(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $unserProbe = $context->module->getNamedFunction('__compiler_unserialize');
        if (null === $unserProbe || 0 === $unserProbe->countBasicBlocks()) {
            $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
            $fn = null !== $unserProbe
                ? $unserProbe
                : $context->module->addFunction('__compiler_unserialize', $ft);
            $entry = $fn->appendBasicBlock('unser_inv_stub');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnVoid();
            $context->registerFunction('__compiler_unserialize', $fn);
        } else {
            $context->registerFunction('__compiler_unserialize', $unserProbe);
        }

        $sessionProbe = $context->module->getNamedFunction('phpc_session_decode_payload');
        if (null === $sessionProbe || 0 === $sessionProbe->countBasicBlocks()) {
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $ft = $context->context->functionType($htPtr, false, $i8p, $sizeT);
            $fn = null !== $sessionProbe
                ? $sessionProbe
                : $context->module->addFunction('phpc_session_decode_payload', $ft);
            $entry = $fn->appendBasicBlock('session_unser_inv_stub');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));
            $context->registerFunction('phpc_session_decode_payload', $fn);
        } else {
            $context->registerFunction('phpc_session_decode_payload', $sessionProbe);
        }

        $context->builder->clearInsertionPosition();
    }
}
