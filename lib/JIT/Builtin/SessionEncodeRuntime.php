<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for session_encode()/session_decode() via SessionEncodeJitHelper PHP (#6086, #9440, #22076).
 *
 * Replaces former ~530-line LLVM wire/apply bodies with thin bridges into {@see VmSessionSerializer} SSOT.
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SessionCreateIdRuntime #21941 / FunctionExistsRuntime #22016).
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
        // Merge bridge for session_decode apply (#26088).
        \PHPCompiler\ext\standard\JitSessionStorageKernel::ensureLinked($context);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_encode_wire', self::implementEncodeWireBridge(...));
        self::implementIfMissing($context, 'phpc_session_decode_wire', self::implementDecodeWireBridge(...));
        self::implementIfMissing($context, '__phpc_session_encode_apply', self::implementEncodeApplyBridge(...));
        self::implementIfMissing($context, '__phpc_session_decode_apply', self::implementDecodeApplyBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
        $encodedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ENCODE_WIRE),
            [$fn->getParam(0)]
        );
        $encoded = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $encodedRaw);
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $encodedRaw);
        $failBb = $fn->appendBasicBlock('se_wire_enc_bridge_fail');
        $okBb = $fn->appendBasicBlock('se_wire_enc_bridge_ok');
        $context->builder->branchIf($isNull, $failBb, $okBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($encoded);
    }

    private static function implementDecodeWireBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_wire_dec_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $decodedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DECODE_WIRE),
            [$fn->getParam(0)]
        );
        $decoded = JitNestedHelperCoerce::coerceToHashtablePtr($context, $decodedRaw);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $decodedRaw);
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
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22076'
        );
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
