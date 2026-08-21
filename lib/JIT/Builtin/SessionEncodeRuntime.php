<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for session_encode()/session_decode() (#6086, #9440, #22076, #33005, #33008, #33261).
 *
 * Encode: LLVM wire walk via {@see \PHPCompiler\ext\standard\JitSessionStorageKernel::emitEncodeWireString}
 * (same as save_to_disk — NestedJIT encodeWire sees strlen=0 on HT keys, #21900).
 * Decode: LLVM wire parse via {@see \PHPCompiler\ext\standard\JitSessionStorageKernel::emitParseWireHashtable}
 * (same as load_from_disk — NestedJIT decodeWire fails thin AOT, #33008).
 * Thin AOT call-site {@see ensureLinked} must {@see BasicBlockHelper::scopeLoweringToFunction}
 * so BasicBlockHelper::append does not steal into the in-flight user fn (#32994 / peer #27211).
 * php-src: ext/session/session.c — php_session_encode / php_session_decode
 *
 * Owns encode/decode ABI decls module-locally (`getNamedFunction` first via
 * {@see declareSessionEncodeAbis}) — do not re-add empty always-on shells in
 * {@see Type} (#31894 / #32122 / #33261).
 */
final class SessionEncodeRuntime
{
    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_session_encode_wire',
        'phpc_session_decode_wire',
        '__phpc_session_encode_apply',
        '__phpc_session_decode_apply',
    ];

    /**
     * Module-local empty decls for Type::register (#33261).
     * Bodies come from {@see ensureLinked}.
     */
    public static function declareSessionEncodeAbis(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $abiName) {
            $probe = $context->module->getNamedFunction($abiName);
            if (null !== $probe) {
                $context->registerFunction($abiName, $probe);
                continue;
            }
            $fn = self::addEmptyDecl($context, $abiName);
            $context->registerFunction($abiName, $fn);
        }
    }

    public static function ensureLinked(Context $context): void
    {
        // Save before StorageKernel — they clear the insert block (#32994).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        StringUnserialize::ensureLinked($context);
        SessionStorageGlobals::ensureGlobals($context);
        // Merge bridge for session_decode apply (#26088) + LLVM wire encode/decode (#33005 / #33008).
        \PHPCompiler\ext\standard\JitSessionStorageKernel::ensureLinked($context);

        self::implementIfMissing($context, 'phpc_session_encode_wire', self::implementEncodeWireBridge(...));
        self::implementIfMissing($context, 'phpc_session_decode_wire', self::implementDecodeWireBridge(...));
        self::implementIfMissing($context, '__phpc_session_encode_apply', self::implementEncodeApplyBridge(...));
        self::implementIfMissing($context, '__phpc_session_decode_apply', self::implementDecodeApplyBridge(...));
        self::registerLinkedRuntime($context);

        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
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
        // Mid-invoke ensureLinked: loweringLlvmFunction is the user fn (#32994 / #27211).
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $name, static function () use ($context, $fn, $emit): void {
            $emit($context, $fn);
        });
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            $context->registerFunction($name, $probe);

            return $probe;
        }
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        return self::addEmptyDecl($context, $name);
    }

    private static function addEmptyDecl(Context $context, string $name): LlvmFunction
    {
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
        // LLVM wire walk — NestedJIT encodeWire sees strlen=0 on HT keys (#21900 / #33005).
        // Same path as phpc_session_save_to_disk; empty HT → null (Zend false).
        $encoded = \PHPCompiler\ext\standard\JitSessionStorageKernel::emitEncodeWireString(
            $context,
            $fn->getParam(0)
        );
        $context->builder->returnValue($encoded);
    }

    private static function implementDecodeWireBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_wire_dec_bridge_entry');
        $context->builder->positionAtEnd($entry);
        // LLVM wire parse — NestedJIT decodeWire fails thin AOT (#21900 / #33008).
        // Same path as phpc_session_load_from_disk; empty payload → empty HT (Zend true).
        $decoded = \PHPCompiler\ext\standard\JitSessionStorageKernel::emitParseWireHashtable(
            $context,
            $fn->getParam(0)
        );
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
        self::emitInactiveWarning(
            $context,
            'session_encode(): Cannot encode non-existent session'
        );
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
        self::emitInactiveWarning(
            $context,
            'session_decode(): Session data cannot be decoded when there is no active session'
        );
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
        // Merge into existing $_SESSION (php-src mod_php.c) — do not replace (#26088).
        if (isset(SuperglobalInit::$globals['_SESSION'])) {
            $existing = $context->builder->load(SuperglobalInit::$globals['_SESSION']);
            $existingNull = $context->builder->icmp(Builder::INT_EQ, $existing, $htPtr->constNull());
            $bbAlloc = $fn->appendBasicBlock('se_dec_alloc_sess');
            $bbMerge = $fn->appendBasicBlock('se_dec_merge');
            $context->builder->branchIf($existingNull, $bbAlloc, $bbMerge);

            $context->builder->positionAtEnd($bbAlloc);
            $fresh = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
            $context->builder->store($fresh, SuperglobalInit::$globals['_SESSION']);
            $context->builder->branch($bbMerge);

            $context->builder->positionAtEnd($bbMerge);
            $dest = $context->builder->load(SuperglobalInit::$globals['_SESSION']);
            $context->builder->call(
                $context->lookupFunction('phpc_session_merge_hashtable'),
                $dest,
                $decoded
            );
        }
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitInactiveWarning(Context $context, string $msg): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast(
            $context->constantFromString($msg),
            $i8p
        );
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $i8p->constNull(),
            $i32->constInt(0, false)
        );
    }

    private static function loadSessionHashtable(Context $context): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        if (isset(SuperglobalInit::$globals['_SESSION'])) {
            return $context->builder->load(SuperglobalInit::$globals['_SESSION']);
        }

        return $htPtr->constNull();
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
                throw new \LogicException($name.' missing after SessionEncodeRuntime bridge (#9440 / #33008)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
