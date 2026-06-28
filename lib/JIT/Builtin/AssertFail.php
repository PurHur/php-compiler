<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of assert() failure bridge (issue #6550, #3157).
 *
 * Replaces __compiler_assert_fail* in lib/AOT/runtime/superglobals_refresh.c.
 * php-src: ext/standard/assert.c
 */
final class AssertFail
{
    private const DEFAULT_MSG = 'assert(): assert(false) failed';

    private const PREFIX = 'Assertion failed: ';

    public static function ensureLinked(Context $context): void
    {
        AssertIniRuntime::ensureGlobals($context);
        AssertionErrorRaise::registerDeclarations($context);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            self::implementBodies($context);
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        AssertIniRuntime::ensureGlobals($context);
        AssertionErrorRaise::registerDeclarations($context);
        self::implementBodies($context);
    }

    private static function implementBodies(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_assert_fail');
        if (null === $probe) {
            return;
        }
        if ($probe->countBasicBlocks() > 0) {
            return;
        }

        AssertionErrorRaise::ensureStandaloneBodies($context);
        self::implementAssertFail($context, $probe);
        $strFn = $context->lookupFunction('__compiler_assert_fail_string');
        self::implementAssertFailString($context, $strFn);
    }

    private static function implementAssertFail(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('assert_fail_entry');
        $context->builder->positionAtEnd($entry);

        $message = $fn->getParam(0);
        $len = $fn->getParam(1);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');

        $nullMsg = $context->builder->icmp(Builder::INT_EQ, $message, $message->typeOf()->constNull());
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $len, $sizeT->constInt(0, false));
        $useDefault = $context->builder->or($nullMsg, $zeroLen);

        $defaultBb = $fn->appendBasicBlock('assert_fail_default');
        $customBb = $fn->appendBasicBlock('assert_fail_custom');
        $doneBb = $fn->appendBasicBlock('assert_fail_done');
        $context->builder->branchIf($useDefault, $defaultBb, $customBb);

        $defaultMsgPtr = $context->builder->pointerCast(
            $context->constantFromString(self::DEFAULT_MSG),
            $i8p
        );
        $defaultLen = $sizeT->constInt(\strlen(self::DEFAULT_MSG), false);

        $context->builder->positionAtEnd($defaultBb);
        self::emitFailWithMessage($context, $fn, $defaultMsgPtr, $defaultLen, $doneBb);

        $context->builder->positionAtEnd($customBb);
        self::emitFailWithMessage($context, $fn, $message, $len, $doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitFailWithMessage(
        Context $context,
        Value $fn,
        Value $msgPtr,
        Value $msgLen,
        BasicBlock $doneBb
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        $exceptionBb = $fn->appendBasicBlock('assert_fail_exception');
        $warningBb = $fn->appendBasicBlock('assert_fail_warning');
        $exceptionOn = AssertIniRuntime::loadExceptionMode($context);
        $context->builder->branchIf($exceptionOn, $exceptionBb, $warningBb);

        $context->builder->positionAtEnd($exceptionBb);
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_assertion_error'),
            $msgPtr,
            $msgLen
        );
        $context->builder->branch($doneBb);

        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $level = $i32->constInt(ErrorReporter::E_USER_WARNING, false);
        $zeroLine = $i32->constInt(0, false);
        $trigger = $context->lookupFunction('__compiler_trigger_error');

        $context->builder->positionAtEnd($warningBb);
        $context->builder->call($trigger, $msgPtr, $msgLen, $level, $emptyFile, $zeroLine);
        $context->builder->branch($doneBb);
    }

    private static function implementAssertFailString(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('assert_fail_str_entry');
        $context->builder->positionAtEnd($entry);

        $description = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $buf = $context->builder->alloca($i8->arrayType(4096), 1, 'assert_msg');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);

        $prefixLen = \strlen(self::PREFIX);
        $prefixPtr = $context->builder->pointerCast(
            $context->constantFromString(self::PREFIX),
            $i8p
        );
        $context->intrinsic->memcpy(
            $bufPtr,
            $prefixPtr,
            $sizeT->constInt($prefixLen, false),
            false
        );

        $map = $context->structFieldMap['__string__'];
        $descLen = $context->builder->load($context->builder->structGep($description, $map['length']));
        $descPtr = $context->builder->pointerCast(
            $context->builder->structGep($description, $map['value']),
            $i8p
        );

        $maxDesc = $context->constantFromInteger(4096 - $prefixLen - 2, 'size_t');
        $descLenCast = $context->builder->trunc($descLen, $sizeT);
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $descLenCast, $maxDesc);

        $clampBb = $fn->appendBasicBlock('assert_fail_str_clamp');
        $copyBb = $fn->appendBasicBlock('assert_fail_str_copy');
        $afterCopyBb = $fn->appendBasicBlock('assert_fail_str_after_copy');
        $context->builder->branchIf($tooLong, $clampBb, $copyBb);

        $context->builder->positionAtEnd($clampBb);
        $context->builder->branch($copyBb);

        $context->builder->positionAtEnd($copyBb);
        $copyLenPhi = $context->builder->phi($sizeT);
        $copyLenPhi->addIncoming($descLenCast, $entry);
        $copyLenPhi->addIncoming($maxDesc, $clampBb);
        $destOff = $context->builder->inBoundsGEP($bufPtr, $sizeT->constInt($prefixLen, false));
        $context->intrinsic->memcpy($destOff, $descPtr, $copyLenPhi, false);
        $totalLen = $context->builder->add(
            $sizeT->constInt($prefixLen, false),
            $copyLenPhi
        );
        $termPtr = $context->builder->inBoundsGEP($bufPtr, $totalLen);
        $context->builder->store($i8->constInt(0, false), $termPtr);
        $context->builder->branch($afterCopyBb);

        $context->builder->positionAtEnd($afterCopyBb);
        $exceptionBb = $fn->appendBasicBlock('assert_fail_str_exception');
        $warningBb = $fn->appendBasicBlock('assert_fail_str_warning');
        $afterFailBb = $fn->appendBasicBlock('assert_fail_str_after_fail');
        $exceptionOn = AssertIniRuntime::loadExceptionMode($context);
        $context->builder->branchIf($exceptionOn, $exceptionBb, $warningBb);

        $context->builder->positionAtEnd($exceptionBb);
        $descLen = $context->builder->sub($totalLen, $sizeT->constInt($prefixLen, false));
        $descPtr = $context->builder->inBoundsGEP($bufPtr, $sizeT->constInt($prefixLen, false));
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_assertion_error'),
            $descPtr,
            $descLen
        );
        $context->builder->branch($afterFailBb);

        $context->builder->positionAtEnd($warningBb);
        $context->builder->call(
            $context->lookupFunction('__compiler_assert_fail'),
            $bufPtr,
            $totalLen
        );
        $context->builder->branch($afterFailBb);

        $context->builder->positionAtEnd($afterFailBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
