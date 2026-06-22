<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM session_encode()/session_decode() php handler wire format (#6086 phase 2, #8252).
 *
 * Wire: {@code key|serialized_value} pairs (php-src ext/session/mod_php.c).
 */
final class SessionEncodeRuntime
{
    private const KIND_NULL = 0;

    private const KIND_BOOL = 1;

    private const KIND_LONG = 2;

    private const KIND_STRING = 3;

    private const KIND_ARRAY = 4;

    private const STR_CAP = 4096;

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        StringSerialize::implement($context);
        StringUnserialize::ensureLinked($context);
        SessionStorageGlobals::ensureGlobals($context);

        self::implementIfMissing($context, 'phpc_session_encode_wire', self::emitEncodeWire(...));
        self::implementIfMissing($context, 'phpc_session_decode_wire', self::emitDecodeWire(...));
        self::implementIfMissing($context, '__phpc_session_encode_apply', self::emitEncodeApply(...));
        self::implementIfMissing($context, '__phpc_session_decode_apply', self::emitDecodeApply(...));
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

    private static function emitEncodeApply(Context $context, LlvmFunction $fn): void
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

    private static function emitDecodeApply(Context $context, LlvmFunction $fn): void
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

    private static function emitEncodeWire(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_wire_enc_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valMap = $context->structFieldMap['__value__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);

        $resultSlot = $context->builder->alloca($strPtr, 1, 'se_enc_acc');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'se_enc_walk');

        $head = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
        $bbEmpty = $fn->appendBasicBlock('se_wire_enc_empty');
        $bbLoopHead = $fn->appendBasicBlock('se_wire_enc_loop_head');
        $bbLoopBody = $fn->appendBasicBlock('se_wire_enc_loop_body');
        $bbFail = $fn->appendBasicBlock('se_wire_enc_fail');
        $bbDone = $fn->appendBasicBlock('se_wire_enc_done');
        $context->builder->branchIf($isEmpty, $bbEmpty, $bbLoopHead);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__alloc'), $zeroI64),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbLoopHead);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__alloc'), $zeroI64),
            $resultSlot
        );
        $context->builder->store($head, $walkSlot);
        $context->builder->branch($bbLoopBody);

        $step = $fn->appendBasicBlock('se_wire_enc_step');
        $context->builder->positionAtEnd($bbLoopBody);
        $walkNode = $context->builder->load($walkSlot);
        $loopDone = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
        $context->builder->branchIf($loopDone, $bbDone, $step);

        $context->builder->positionAtEnd($step);
        $nodeKey = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['key']));
        $badKey = self::stringContainsPipe($context, $nodeKey);
        $bbAfterKey = BasicBlockHelper::append($context, 'se_wire_enc_after_key');
        $context->builder->branchIf($badKey, $bbFail, $bbAfterKey);

        $context->builder->positionAtEnd($bbAfterKey);
        $valPtr = $context->builder->structGep($walkNode, $nodeMap['value']);
        $serialized = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_value'),
            $valPtr
        );
        $acc = $context->builder->load($resultSlot);
        $withKey = JitStringConcat::concat($context, $acc, $nodeKey);
        $pipe = self::literalString($context, '|');
        $withPipe = JitStringConcat::concat($context, $withKey, $pipe);
        $withVal = JitStringConcat::concat($context, $withPipe, $serialized);
        $context->builder->store($withVal, $resultSlot);
        $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
        $context->builder->store($nextWalk, $walkSlot);
        $context->builder->branch($bbLoopBody);

        $context->builder->positionAtEnd($bbFail);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnValue($context->builder->load($resultSlot));
    }

    private static function emitDecodeWire(Context $context, LlvmFunction $fn): void
    {
        LibcExtern::register($context);
        $entry = $fn->appendBasicBlock('se_wire_dec_entry');
        $context->builder->positionAtEnd($entry);

        $payload = $fn->getParam(0);
        $strMap = $context->structFieldMap['__string__'];
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $pipeByte = $i32->constInt(ord('|'), false);

        $bodyLen = $context->builder->load($context->builder->structGep($payload, $strMap['length']));
        $bodyPtr = $context->builder->pointerCast(
            $context->builder->structGep($payload, $strMap['value']),
            $i8p
        );
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $bodyLen, $i64->constInt(0, false));

        $kindSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $boolSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $longSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $strBuf = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8')->arrayType(self::STR_CAP));
        $strBufPtr = $context->builder->pointerCast($strBuf, $i8p);
        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $posSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $end = $context->builder->inBoundsGEP($bodyPtr, $context->builder->zExt($bodyLen, $sizeT));

        $bbEmpty = $fn->appendBasicBlock('se_wire_dec_empty');
        $bbWork = $fn->appendBasicBlock('se_wire_dec_work');
        $bbFail = $fn->appendBasicBlock('se_wire_dec_fail');
        $context->builder->branchIf($zeroLen, $bbEmpty, $bbWork);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        $context->builder->positionAtEnd($bbWork);
        $htOut = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($bodyPtr, $posSlot);

        $loopHead = $fn->appendBasicBlock('se_wire_dec_loop_head');
        $loopBody = $fn->appendBasicBlock('se_wire_dec_loop_body');
        $okBb = $fn->appendBasicBlock('se_wire_dec_ok');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $pos, $end);
        $context->builder->branchIf($atEnd, $okBb, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $pos = $context->builder->load($posSlot);
        $remain = $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($pos, $i64)
        );
        $pipePtr = $context->builder->call(
            $context->lookupFunction('memchr'),
            $pos,
            $pipeByte,
            $context->builder->truncOrBitCast($remain, $sizeT)
        );
        $noPipe = $context->builder->icmp(Builder::INT_EQ, $pipePtr, $i8p->constNull());
        $bbAfterPipe = BasicBlockHelper::append($context, 'se_wire_dec_after_pipe');
        $context->builder->branchIf($noPipe, $bbFail, $bbAfterPipe);

        $context->builder->positionAtEnd($bbAfterPipe);
        $keyLen = $context->builder->sub(
            $context->builder->ptrToInt($pipePtr, $i64),
            $context->builder->ptrToInt($pos, $i64)
        );
        $keyLenZero = $context->builder->icmp(Builder::INT_SLE, $keyLen, $i64->constInt(0, false));
        $bbKeyOk = BasicBlockHelper::append($context, 'se_wire_dec_key_ok');
        $context->builder->branchIf($keyLenZero, $bbFail, $bbKeyOk);

        $context->builder->positionAtEnd($bbKeyOk);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $keyLen,
            $pos
        );
        $valStart = $context->builder->inBoundsGEP($pipePtr, $sizeT->constInt(1, false));
        $context->builder->store($valStart, $posSlot);
        $parsed = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_item'),
            $posSlot,
            $end,
            $kindSlot,
            $boolSlot,
            $longSlot,
            $strBufPtr,
            $htSlot
        );
        $parseOk = $context->i32Success($parsed);
        $bbStore = BasicBlockHelper::append($context, 'se_wire_dec_store');
        $context->builder->branchIf($parseOk, $bbStore, $bbFail);

        $context->builder->positionAtEnd($bbStore);
        self::emitHashtableSetParsed($context, $htOut, $keyStr, $kindSlot, $boolSlot, $longSlot, $strBufPtr, $htSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($bbFail);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($htOut);
    }

    private static function emitHashtableSetParsed(
        Context $context,
        Value $ht,
        Value $keyStr,
        Value $kindSlot,
        Value $boolSlot,
        Value $longSlot,
        Value $strBufPtr,
        Value $htSlot
    ): void {
        $fn = $context->builder->getInsertBlock()->getParent();
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $kind = $context->builder->load($kindSlot);

        $bbEntry = $context->builder->getInsertBlock();
        $bbCheckBool = $fn->appendBasicBlock('se_set_check_bool');
        $bbBool = $fn->appendBasicBlock('se_set_bool');
        $bbCheckLong = $fn->appendBasicBlock('se_set_check_long');
        $bbLong = $fn->appendBasicBlock('se_set_long');
        $bbCheckString = $fn->appendBasicBlock('se_set_check_string');
        $bbString = $fn->appendBasicBlock('se_set_string');
        $bbCheckArray = $fn->appendBasicBlock('se_set_check_array');
        $bbArray = $fn->appendBasicBlock('se_set_array');
        $bbDone = $fn->appendBasicBlock('se_set_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $i32->constInt(self::KIND_NULL, false));
        $context->builder->branchIf($isNull, $bbDone, $bbCheckBool);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $kind, $i32->constInt(self::KIND_BOOL, false));
        $context->builder->branchIf($isBool, $bbBool, $bbCheckLong);

        $context->builder->positionAtEnd($bbBool);
        $boolVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($boolSlot),
            $i32->constInt(0, false)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $keyStr,
            $boolVal
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckLong);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $kind, $i32->constInt(self::KIND_LONG, false));
        $context->builder->branchIf($isLong, $bbLong, $bbCheckString);

        $context->builder->positionAtEnd($bbLong);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $context->builder->load($longSlot)
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckString);
        $isString = $context->builder->icmp(Builder::INT_EQ, $kind, $i32->constInt(self::KIND_STRING, false));
        $context->builder->branchIf($isString, $bbString, $bbCheckArray);

        $context->builder->positionAtEnd($bbString);
        $valStr = $context->builder->call(
            $context->lookupFunction('__phpc_unser_cstr_to_string'),
            $strBufPtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckArray);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $kind, $i32->constInt(self::KIND_ARRAY, false));
        $context->builder->branchIf($isArray, $bbArray, $bbDone);

        $context->builder->positionAtEnd($bbArray);
        $subHt = $context->builder->load($htSlot);
        $hasHt = $context->builder->icmp(Builder::INT_NE, $subHt, $htPtr->constNull());
        $bbArrStore = $fn->appendBasicBlock('se_set_array_store');
        $context->builder->branchIf($hasHt, $bbArrStore, $bbDone);
        $context->builder->positionAtEnd($bbArrStore);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $keyStr,
            $subHt
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function stringContainsPipe(Context $context, Value $str): Value
    {
        LibcExtern::register($context);
        $strMap = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $pipeByte = $i32->constInt(ord('|'), false);
        $body = $context->builder->pointerCast(
            $context->builder->structGep($str, $strMap['value']),
            $i8p
        );
        $len = $context->builder->load($context->builder->structGep($str, $strMap['length']));
        $found = $context->builder->call(
            $context->lookupFunction('memchr'),
            $body,
            $pipeByte,
            $context->builder->truncOrBitCast($len, $sizeT)
        );

        return $context->builder->icmp(Builder::INT_NE, $found, $i8p->constNull());
    }

    private static function loadSessionHashtable(Context $context): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        if (isset(SuperglobalInit::$globals['_SESSION'])) {
            return $context->builder->load(SuperglobalInit::$globals['_SESSION']);
        }

        return $htPtr->constNull();
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->getTypeFromString('int64')->constInt(strlen($text), false);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
    }

}
