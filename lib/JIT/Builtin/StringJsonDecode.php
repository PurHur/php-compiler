<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for json_decode runtime helpers via JsonDecodeJitHelper PHP (#9359, #13228, #20829, #24137).
 *
 * Embed + thin standalone AOT: single {@see __compiler_json_decode} bridge with tag dispatch
 * (Unserialize #20785 / Explode #14750 shape — no thin null stubs).
 * Validate/last_error live in {@see JsonValidateJitHelper} (separate NestedJIT TU).
 * php-src: ext/json/php_json.c — php_json_decode_ex / php_json_validate
 */
final class StringJsonDecode
{
    private const DECODE_HELPER_PATH = '/ext/standard/JsonDecodeJitHelper.php';

    private const VALIDATE_HELPER_PATH = '/ext/standard/JsonValidateJitHelper.php';

    private const TAG_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::resultTag';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decodeInto';

    private const INT_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decodeInt';

    private const BOOL_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decodeBool';

    private const FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decodeFloat';

    private const STRING_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decodeString';

    private const VALIDATE_HELPER = 'PHPCompiler\\ext\\standard\\JsonValidateJitHelper::validate';

    private const LAST_ERROR_HELPER = 'PHPCompiler\\ext\\standard\\JsonValidateJitHelper::lastError';

    private const LAST_ERROR_MSG_HELPER = 'PHPCompiler\\ext\\standard\\JsonValidateJitHelper::lastErrorMsg';

    private const SET_LAST_ERROR_HELPER = 'PHPCompiler\\ext\\standard\\JsonValidateJitHelper::setLastError';

    private const DECODE_BRIDGE_ENTRY = 'json_decode_bridge_entry';

    private const VALIDATE_BRIDGE_ENTRY = 'json_validate_bridge_entry';

    private const LAST_ERROR_BRIDGE_ENTRY = 'json_last_error_bridge_entry';

    private const LAST_ERROR_MSG_BRIDGE_ENTRY = 'json_last_error_msg_bridge_entry';

    private const SET_LAST_ERROR_BRIDGE_ENTRY = 'json_set_last_error_bridge_entry';

    /** @var list<string> */
    private const DECODE_COMPILED_HELPERS = [
        self::TAG_HELPER,
        self::DECODE_HELPER,
        self::INT_HELPER,
        self::BOOL_HELPER,
        self::FLOAT_HELPER,
        self::STRING_HELPER,
    ];

    /** @var list<string> */
    private const VALIDATE_COMPILED_HELPERS = [
        self::VALIDATE_HELPER,
        self::LAST_ERROR_HELPER,
        self::LAST_ERROR_MSG_HELPER,
        self::SET_LAST_ERROR_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_json_decode',
        '__compiler_json_validate',
        '__compiler_json_last_error',
        '__compiler_json_last_error_msg',
        '__compiler_json_set_last_error',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Standalone AOT: JSON POST helper for superglobals_refresh.c (#7389). */
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
        foreach (['add', 'addindex', 'append', 'updateindex'] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($context, $htMethod);
        }
        self::ensureNativeHtInternalProxies($context);

        $decodeProbe = $context->module->getNamedFunction('__compiler_json_decode');
        $validateProbe = $context->module->getNamedFunction('__compiler_json_validate');
        $lastErrProbe = $context->module->getNamedFunction('__compiler_json_last_error');
        $lastMsgProbe = $context->module->getNamedFunction('__compiler_json_last_error_msg');
        $setErrProbe = $context->module->getNamedFunction('__compiler_json_set_last_error');
        if (JitVmHelperLink::hasNamedBridgeEntry($decodeProbe, self::DECODE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($validateProbe, self::VALIDATE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($lastErrProbe, self::LAST_ERROR_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($lastMsgProbe, self::LAST_ERROR_MSG_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($setErrProbe, self::SET_LAST_ERROR_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }
        if (null !== $decodeProbe && $decodeProbe->countBasicBlocks() > 0
            && null !== $validateProbe && $validateProbe->countBasicBlocks() > 0
            && null !== $lastErrProbe && $lastErrProbe->countBasicBlocks() > 0
            && null !== $lastMsgProbe && $lastMsgProbe->countBasicBlocks() > 0
            && null !== $setErrProbe && $setErrProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }

        self::implementDecodeBridge($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_validate',
            self::VALIDATE_BRIDGE_ENTRY,
            [$strPtr, $i64, $i64],
            $i64,
            self::VALIDATE_HELPER,
            self::VALIDATE_HELPER_PATH,
            self::VALIDATE_COMPILED_HELPERS,
            '#20829'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_last_error',
            self::LAST_ERROR_BRIDGE_ENTRY,
            [],
            $i64,
            self::LAST_ERROR_HELPER,
            self::VALIDATE_HELPER_PATH,
            self::VALIDATE_COMPILED_HELPERS,
            '#20829'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_last_error_msg',
            self::LAST_ERROR_MSG_BRIDGE_ENTRY,
            [],
            $strPtr,
            self::LAST_ERROR_MSG_HELPER,
            self::VALIDATE_HELPER_PATH,
            self::VALIDATE_COMPILED_HELPERS,
            '#20829'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_set_last_error',
            self::SET_LAST_ERROR_BRIDGE_ENTRY,
            [$i64],
            $i64,
            self::SET_LAST_ERROR_HELPER,
            self::VALIDATE_HELPER_PATH,
            self::VALIDATE_COMPILED_HELPERS,
            '#26792'
        );
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::DECODE_HELPER_PATH,
            self::DECODE_COMPILED_HELPERS,
            '#20829'
        );
    }

    /** Register phpc_native_ht_* Internal JIT handlers before nested JsonDecodeJitHelper compile (#24137). */
    private static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_alloc(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_ht(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_long(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_hashtable_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_long_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after JsonDecodeJitHelper compile (#20829)');
        }

        return $fn;
    }

    /**
     * NestedJIT assoc runtime json_decode(): tag dispatch → __value__* or __hashtable__* (#24137).
     */
    private static function implementDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_json_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::DECODE_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidPtr = $context->getTypeFromString('void*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $doubleTy = $context->getTypeFromString('double');
        $ft = $context->context->functionType($valuePtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        self::ensureJitHelperCompiled($context);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::DECODE_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $payload = $fn->getParam(0);
        // Own + pin: NestedJIT string-param addref/delref frees heap __string__* mid-call
        // (length survives, content UAF). Constant strings already use disableRefcount (#24137).
        // Combined with ARG_RECV skip of raw formal rebind in JIT.php (#24137).
        $payloadOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $context->refcount->disableRefcount($payloadOwned);
        // Payload coerce is per-callee below: decodeInto may be `__string__*` while
        // decodeBool/Int/Float/String take by-value `__value__` (#24465).

        // Peek first byte in LLVM (__string__.value is trailing int8 payload, not int8*).
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($payloadOwned, $strMap['length'])
        );
        $bytesPtr = $context->builder->structGep($payloadOwned, $strMap['value']);
        $firstByte = $context->builder->load($bytesPtr);
        $firstExt = $context->builder->zExt($firstByte, $i64);

        $bbNull = $fn->appendBasicBlock('json_decode_bridge_null');
        $bbBool = $fn->appendBasicBlock('json_decode_bridge_bool');
        $bbInt = $fn->appendBasicBlock('json_decode_bridge_int');
        $bbFloat = $fn->appendBasicBlock('json_decode_bridge_float');
        $bbString = $fn->appendBasicBlock('json_decode_bridge_string');
        $bbArray = $fn->appendBasicBlock('json_decode_bridge_array');
        $bbMerge = $fn->appendBasicBlock('json_decode_bridge_merge');
        $bbEmpty = $fn->appendBasicBlock('json_decode_bridge_empty');
        $bbPeek = $fn->appendBasicBlock('json_decode_bridge_peek');

        $empty = $context->builder->icmp(Builder::INT_EQ, $strLen, $i64->constInt(0, false));
        $context->builder->branchIf($empty, $bbEmpty, $bbPeek);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->branch($bbNull);

        $context->builder->positionAtEnd($bbPeek);
        $switchInst = $context->builder->branchSwitch($firstExt, $bbNull, 8);
        $switchInst->addCase($i64->constInt(\ord('{'), false), $bbArray);
        $switchInst->addCase($i64->constInt(\ord('['), false), $bbArray);
        $switchInst->addCase($i64->constInt(\ord('"'), false), $bbString);
        $switchInst->addCase($i64->constInt(\ord('t'), false), $bbBool);
        $switchInst->addCase($i64->constInt(\ord('f'), false), $bbBool);
        $switchInst->addCase($i64->constInt(\ord('n'), false), $bbNull);
        $switchInst->addCase($i64->constInt(\ord('-'), false), $bbInt);
        for ($d = \ord('0'); $d <= \ord('9'); ++$d) {
            $switchInst->addCase($i64->constInt($d, false), $bbInt);
        }

        $context->builder->positionAtEnd($bbNull);
        $slotNull = JitValueBox::alloc($context);
        $ptrNull = JitValueBox::pointer($context, $slotNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptrNull);
        $castNull = $context->builder->pointerCast($ptrNull, $voidPtr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbBool);
        $slotBool = JitValueBox::alloc($context);
        $ptrBool = JitValueBox::pointer($context, $slotBool);
        $boolHelper = self::helperFunction($context, self::BOOL_HELPER);
        $boolArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadOwned,
            $boolHelper->getParam(0)->typeOf()
        );
        $boolRaw = $context->builder->call($boolHelper, $boolArg);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptrBool,
            $context->builder->zExt(
                JitNestedHelperCoerce::coerceBridgeResult($context, $boolRaw, $i1),
                $i32
            )
        );
        $castBool = $context->builder->pointerCast($ptrBool, $voidPtr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbInt);
        $slotInt = JitValueBox::alloc($context);
        $ptrInt = JitValueBox::pointer($context, $slotInt);
        $intHelper = self::helperFunction($context, self::INT_HELPER);
        $intArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadOwned,
            $intHelper->getParam(0)->typeOf()
        );
        $long = $context->builder->call($intHelper, $intArg);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptrInt,
            JitNestedHelperCoerce::coerceBridgeResult($context, $long, $i64)
        );
        $castInt = $context->builder->pointerCast($ptrInt, $voidPtr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbFloat);
        $slotFloat = JitValueBox::alloc($context);
        $ptrFloat = JitValueBox::pointer($context, $slotFloat);
        $floatHelper = self::helperFunction($context, self::FLOAT_HELPER);
        $floatArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadOwned,
            $floatHelper->getParam(0)->typeOf()
        );
        $dbl = $context->builder->call($floatHelper, $floatArg);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $ptrFloat,
            JitNestedHelperCoerce::coerceBridgeResult($context, $dbl, $doubleTy)
        );
        $castFloat = $context->builder->pointerCast($ptrFloat, $voidPtr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbString);
        $slotStr = JitValueBox::alloc($context);
        $ptrStr = JitValueBox::pointer($context, $slotStr);
        $stringHelper = self::helperFunction($context, self::STRING_HELPER);
        $stringArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadOwned,
            $stringHelper->getParam(0)->typeOf()
        );
        $strRaw = $context->builder->call($stringHelper, $stringArg);
        $strVal = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strRaw);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptrStr,
            $strVal
        );
        $castStr = $context->builder->pointerCast($ptrStr, $voidPtr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbArray);
        // Allocate HT in the bridge — NestedJIT cannot return HT* as i64 (#24137).
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $destI64 = JitNestedHelperCoerce::ptrToI64($context, $ht);
        $destSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($destI64, $destSlot);
        $destArg = $context->builder->load($destSlot);
        $decodeHelper = self::helperFunction($context, self::DECODE_HELPER);
        $destCoerced = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $destArg,
            $decodeHelper->getParam(0)->typeOf()
        );
        $payloadArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadOwned,
            $decodeHelper->getParam(1)->typeOf()
        );
        $okRaw = $context->builder->call(
            $decodeHelper,
            $destCoerced,
            $payloadArg
        );
        $ok = JitNestedHelperCoerce::coerceBridgeResult($context, $okRaw, $i64);
        $failed = $context->builder->icmp(
            Builder::INT_EQ,
            $ok,
            $i64->constInt(0, false)
        );
        $bbArrayFail = $fn->appendBasicBlock('json_decode_bridge_array_fail');
        $bbArrayOk = $fn->appendBasicBlock('json_decode_bridge_array_ok');
        $context->builder->branchIf($failed, $bbArrayFail, $bbArrayOk);

        $context->builder->positionAtEnd($bbArrayFail);
        $slotArrNull = JitValueBox::alloc($context);
        $ptrArrNull = JitValueBox::pointer($context, $slotArrNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptrArrNull);
        $castArrNull = $context->builder->pointerCast($ptrArrNull, $voidPtr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbArrayOk);
        // Box HT into __value__* — raw HT* cast to value* is misread as int/NULL (#24137).
        $slotHt = JitValueBox::alloc($context);
        $ptrHt = JitValueBox::pointer($context, $slotHt);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptrHt,
            $ht
        );
        $context->refcount->addref($ht);
        $castHt = $context->builder->pointerCast($ptrHt, $voidPtr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);
        $phi = $context->builder->phi($voidPtr, 'json_decode_bridge_result');
        $phi->addIncoming($castNull, $bbNull);
        $phi->addIncoming($castBool, $bbBool);
        $phi->addIncoming($castInt, $bbInt);
        $phi->addIncoming($castFloat, $bbFloat);
        $phi->addIncoming($castStr, $bbString);
        $phi->addIncoming($castArrNull, $bbArrayFail);
        $phi->addIncoming($castHt, $bbArrayOk);

        $context->builder->returnValue($context->builder->pointerCast($phi, $valuePtr));
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringJsonDecode bridge (#20829)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
