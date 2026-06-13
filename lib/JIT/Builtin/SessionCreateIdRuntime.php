<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM session_create_id() for JIT/AOT (issue #6002 phase 2).
 *
 * php-src: ext/session/session.c — php_session_create_id
 */
final class SessionCreateIdRuntime
{
    private const HEX_TABLE = '0123456789abcdef';

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        SessionLifecycleRuntime::ensureLinked($context);
        SessionStorageRuntime::ensureLinked($context);

        self::implementIfMissing($context, 'phpc_session_random_id_string', self::emitRandomIdString(...));
        self::implementIfMissing($context, '__phpc_session_create_id_apply', self::emitCreateIdApply(...));
        self::implementIfMissing($context, '__phpc_session_create_id_apply_boxed', self::emitCreateIdApplyBoxed(...));
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

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');

        return match ($name) {
            'phpc_session_random_id_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false)
            ),
            '__phpc_session_create_id_apply' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr, $strPtr)
            ),
            '__phpc_session_create_id_apply_boxed' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr, $valuePtr)
            ),
            default => throw new \LogicException('Unknown session create id JIT helper: '.$name),
        };
    }

    private static function emitRandomIdString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('srid_rand_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $sixteen = $i64->constInt(16, false);
        $thirtyTwo = $i64->constInt(32, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);

        $raw = $context->builder->call($context->lookupFunction('__compiler_random_bytes'), $sixteen);
        $rawNull = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'srid_rand_empty');
        $bbEncode = BasicBlockHelper::append($context, 'srid_rand_encode');
        $context->builder->branchIf($rawNull, $bbEmpty, $bbEncode);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($bbEncode);
        $outBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($thirtyTwo, $oneI64)
        );
        $outPtr = $context->builder->pointerCast($outBuf, $i8p);
        $strMap = $context->structFieldMap['__string__'];
        $rawBytes = $context->builder->structGep($raw, $strMap['value']);
        $hexBase = $context->builder->pointerCast(self::hexTableGlobal($context), $i8p);
        $iSlot = $context->builder->alloca($i64, 1, 'srid_rand_i');
        $context->builder->store($zeroI64, $iSlot);
        $loopHead = BasicBlockHelper::append($context, 'srid_rand_loop_head');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $loopDone = BasicBlockHelper::append($context, 'srid_rand_loop_done');
        $loopBody = BasicBlockHelper::append($context, 'srid_rand_loop_body');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $i, $thirtyTwo),
            $loopDone,
            $loopBody
        );

        $context->builder->positionAtEnd($loopBody);
        $byteIdx = $context->builder->lshr($i, $oneI64);
        $bytePtr = $context->builder->inBoundsGEP($rawBytes, $byteIdx);
        $byte = $context->builder->load($bytePtr);
        $isLow = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($i, $oneI64),
            $zeroI64
        );
        $highNibble = $context->builder->lshr($byte, $i8->constInt(4, false));
        $lowNibble = $context->builder->and($byte, $i8->constInt(0x0f, false));
        $nibble = $context->builder->select($isLow, $lowNibble, $highNibble);
        $hexPtr = $context->builder->inBoundsGEP(
            $hexBase,
            $context->builder->zext($nibble, $i64)
        );
        $hexChar = $context->builder->load($hexPtr);
        $outAt = $context->builder->inBoundsGEP($outPtr, $i);
        $context->builder->store($hexChar, $outAt);
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($outPtr, $thirtyTwo));
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $thirtyTwo,
            $outPtr
        );
        $context->builder->returnValue($result);
    }

    private static function emitCreateIdApply(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('scid_apply_entry');
        $context->builder->positionAtEnd($entry);

        $outPtr = $fn->getParam(0);
        $prefix = $fn->getParam(1);
        $strPtr = $context->getTypeFromString('__string__*');
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $maxLen = $i64->constInt(VmSession::MAX_ID_LEN, false);

        $generated = $context->builder->call($context->lookupFunction('phpc_session_random_id_string'));
        $genNull = $context->builder->icmp(Builder::INT_EQ, $generated, $strPtr->constNull());
        $bbFail = BasicBlockHelper::append($context, 'scid_fail');
        $bbPrefixCheck = BasicBlockHelper::append($context, 'scid_prefix_check');
        $context->builder->branchIf($genNull, $bbFail, $bbPrefixCheck);

        $context->builder->positionAtEnd($bbPrefixCheck);
        $prefixNull = $context->builder->icmp(Builder::INT_EQ, $prefix, $strPtr->constNull());
        $bbNoPrefix = BasicBlockHelper::append($context, 'scid_no_prefix');
        $bbHasPrefix = BasicBlockHelper::append($context, 'scid_has_prefix');
        $context->builder->branchIf($prefixNull, $bbNoPrefix, $bbHasPrefix);

        $context->builder->positionAtEnd($bbNoPrefix);
        $context->builder->call($context->lookupFunction('__value__writeString'), $outPtr, $generated);
        $bbDone = BasicBlockHelper::append($context, 'scid_done');
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbHasPrefix);
        $prefixLen = $context->builder->load($context->builder->structGep($prefix, $strMap['length']));
        $prefixEmpty = $context->builder->icmp(Builder::INT_SLE, $prefixLen, $zeroI64);
        $bbValidate = BasicBlockHelper::append($context, 'scid_prefix_validate');
        $context->builder->branchIf($prefixEmpty, $bbNoPrefix, $bbValidate);

        $context->builder->positionAtEnd($bbValidate);
        $tooLong = $context->builder->icmp(Builder::INT_SGT, $prefixLen, $maxLen);
        $bbValueErr = BasicBlockHelper::append($context, 'scid_value_err');
        $bbSanitize = BasicBlockHelper::append($context, 'scid_sanitize');
        $context->builder->branchIf($tooLong, $bbValueErr, $bbSanitize);

        $context->builder->positionAtEnd($bbValueErr);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'session_create_id(): Argument #1 ($prefix) cannot be longer than '
            .VmSession::MAX_ID_LEN.' characters'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($bbSanitize);
        $sanitized = SessionStorageRuntime::sanitizeIdString($context, $prefix);
        $sanitizedLen = $context->builder->load($context->builder->structGep($sanitized, $strMap['length']));
        $prefixValid = $context->builder->icmp(Builder::INT_EQ, $prefixLen, $sanitizedLen);
        $bbConcat = BasicBlockHelper::append($context, 'scid_concat');
        $context->builder->branchIf($prefixValid, $bbConcat, $bbFail);

        $context->builder->positionAtEnd($bbConcat);
        $combined = JitStringConcat::concat($context, $sanitized, $generated);
        $context->builder->call($context->lookupFunction('__value__writeString'), $outPtr, $combined);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbFail);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitCreateIdApplyBoxed(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('scid_boxed_entry');
        $context->builder->positionAtEnd($entry);

        $outPtr = $fn->getParam(0);
        $boxed = $fn->getParam(1);
        $strPtr = $context->getTypeFromString('__string__*');
        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $nullStr = $strPtr->constNull();

        $typeByte = $context->builder->load($context->builder->structGep($boxed, $valMap['type']));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\JIT\Variable::TYPE_NULL, false)
        );
        $bbNull = BasicBlockHelper::append($context, 'scid_boxed_null');
        $bbString = BasicBlockHelper::append($context, 'scid_boxed_string');
        $context->builder->branchIf($isNull, $bbNull, $bbString);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_create_id_apply'),
            $outPtr,
            $nullStr
        );
        $bbDone = BasicBlockHelper::append($context, 'scid_boxed_done');
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbString);
        $prefixStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxed
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_session_create_id_apply'),
            $outPtr,
            $prefixStr
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function hexTableGlobal(Context $context): Value
    {
        return $context->constantFromString(self::HEX_TABLE);
    }
}
