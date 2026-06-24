<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\SuperglobalInit;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for session_encode()/session_decode() via SessionEncodeJitHelper PHP (#6086, #9440).
 *
 * Replaces former ~530-line LLVM wire/apply bodies with thin bridges into {@see VmSessionSerializer} SSOT.
 * php-src: ext/session/session.c — php_session_encode / php_session_decode
 */
final class SessionEncodeRuntime
{
    private const HELPER_PATH = '/ext/standard/SessionEncodeJitHelper.php';

    private const ENCODE_WIRE = 'PHPCompiler\\ext\\standard\\SessionEncodeJitHelper::encodeWire';

    private const DECODE_WIRE = 'PHPCompiler\\ext\\standard\\SessionEncodeJitHelper::decodeWire';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_WIRE,
        self::DECODE_WIRE,
    ];

    public static function ensureLinked(Context $context): void
    {
        StringUnserialize::ensureLinked($context);
        SessionStorageGlobals::ensureGlobals($context);

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_encode_wire', self::implementEncodeWireBridge(...));
        self::implementIfMissing($context, 'phpc_session_decode_wire', self::implementDecodeWireBridge(...));
        self::implementIfMissing($context, '__phpc_session_encode_apply', self::implementEncodeApplyBridge(...));
        self::implementIfMissing($context, '__phpc_session_decode_apply', self::implementDecodeApplyBridge(...));
        self::registerLinkedRuntime($context);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');

        return match ($name) {
            'phpc_session_encode_wire' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $htPtr)
            ),
            'phpc_session_decode_wire' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr)
            ),
            '__phpc_session_encode_apply' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr)
            ),
            '__phpc_session_decode_apply' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr, $strPtr)
            ),
            default => throw new \LogicException('Unknown session encode JIT helper: '.$name),
        };
    }

    private static function implementEncodeWireBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_wire_enc_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $encodedRaw = $context->builder->call(
            self::helperFunction($context, self::ENCODE_WIRE),
            $fn->getParam(0)
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $encoded = $context->builder->bitcast($encodedRaw, $strPtr);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $encoded, $strPtr->constNull());
        $failBb = $fn->appendBasicBlock('se_wire_enc_bridge_fail');
        $okBb = $fn->appendBasicBlock('se_wire_enc_bridge_ok');
        $context->builder->branchIf($isNull, $failBb, $okBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($encoded);
    }

    private static function implementDecodeWireBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_wire_dec_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $decodedRaw = $context->builder->call(
            self::helperFunction($context, self::DECODE_WIRE),
            $fn->getParam(0)
        );
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $decoded = $context->builder->bitcast($decodedRaw, $htPtr);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $decoded, $htPtr->constNull());
        $failBb = $fn->appendBasicBlock('se_wire_dec_bridge_fail');
        $okBb = $fn->appendBasicBlock('se_wire_dec_bridge_ok');
        $context->builder->branchIf($isNull, $failBb, $okBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($decoded);
    }

    private static function implementEncodeApplyBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_enc_apply_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI8 = $i8->constInt(0, false);
        $outPtr = $fn->getParam(0);

        $bbInactive = BasicBlockHelper::append($context, 'se_enc_inactive');
        $bbWork = BasicBlockHelper::append($context, 'se_enc_work');
        $bbFail = BasicBlockHelper::append($context, 'se_enc_fail');
        $bbOk = BasicBlockHelper::append($context, 'se_enc_ok');
        $bbDone = BasicBlockHelper::append($context, 'se_enc_done');

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbWork, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbWork);
        $session = self::loadSessionHashtable($context);
        $hasSession = $context->builder->icmp(Builder::INT_NE, $session, $htPtr->constNull());
        $emptyHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $sessionHt = $context->builder->select($hasSession, $session, $emptyHt);
        $encoded = $context->builder->call(
            $context->lookupFunction('phpc_session_encode_wire'),
            $sessionHt
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $encoded, $strPtr->constNull());
        $context->builder->branchIf($isNull, $bbFail, $bbOk);

        $context->builder->positionAtEnd($bbFail);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        $context->builder->call($context->lookupFunction('__value__writeString'), $outPtr, $encoded);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function implementDecodeApplyBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_dec_apply_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroI8 = $i8->constInt(0, false);
        $outPtr = $fn->getParam(0);
        $payload = $fn->getParam(1);

        $bbInactive = BasicBlockHelper::append($context, 'se_dec_inactive');
        $bbWork = BasicBlockHelper::append($context, 'se_dec_work');
        $bbFail = BasicBlockHelper::append($context, 'se_dec_fail');
        $bbOk = BasicBlockHelper::append($context, 'se_dec_ok');
        $bbDone = BasicBlockHelper::append($context, 'se_dec_done');

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbWork, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbWork);
        $decoded = $context->builder->call(
            $context->lookupFunction('phpc_session_decode_wire'),
            $payload
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $decoded, $htPtr->constNull());
        $context->builder->branchIf($isNull, $bbFail, $bbOk);

        $context->builder->positionAtEnd($bbFail);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        if (isset(SuperglobalInit::$globals['_SESSION'])) {
            $context->builder->store($decoded, SuperglobalInit::$globals['_SESSION']);
        }
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function loadSessionHashtable(Context $context): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        if (isset(SuperglobalInit::$globals['_SESSION'])) {
            return $context->builder->load(SuperglobalInit::$globals['_SESSION']);
        }

        return $htPtr->constNull();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SessionEncodeJitHelper compile (#9440)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SessionEncodeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SessionEncodeJitHelper.php parseAndCompile failed (#9440)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9440)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([
            'phpc_session_encode_wire',
            'phpc_session_decode_wire',
            '__phpc_session_encode_apply',
            '__phpc_session_decode_apply',
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after SessionEncodeRuntime bridge (#9440)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
