<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for session_create_id() via SessionCreateIdJitHelper PHP (#9500, #21941).
 *
 * Replaces hex-table / entropy LLVM in this file; SSOT {@see \PHPCompiler\ext\standard\VmSession}.
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ProcessRuntime #21857).
 * php-src: ext/session/session.c — php_session_create_id
 */
final class SessionCreateIdRuntime
{
    private const HELPER_PATH = '/ext/standard/SessionCreateIdJitHelper.php';

    private const RANDOM_ID = 'PHPCompiler\\ext\\standard\\SessionCreateIdJitHelper::randomIdString';

    private const CREATE_ID = 'PHPCompiler\\ext\\standard\\SessionCreateIdJitHelper::createIdNullable';

    private const CREATE_ID_WITH_PREFIX = 'PHPCompiler\\ext\\standard\\SessionCreateIdJitHelper::createIdWithPrefix';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RANDOM_ID,
        self::CREATE_ID,
        self::CREATE_ID_WITH_PREFIX,
    ];

    public static function ensureLinked(Context $context): void
    {
        // Create-id ABI only — do not pull SessionLifecycleRuntime (session_start AOT
        // NestedJIT still segfaults on master; create_id must link standalone) (#27258).
        SessionStorageGlobals::ensureGlobals($context);
        StringRandomBytes::ensureLinked($context);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_random_id_string', self::implementRandomIdString(...));
        self::implementIfMissing($context, '__phpc_session_create_id_apply', self::implementCreateIdApply(...));
        self::implementIfMissing($context, '__phpc_session_create_id_apply_boxed', self::implementCreateIdApplyBoxed(...));

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** Thin user-script AOT: materialize ABI bodies on first use (#27258 / peer #12910). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** Random id ABI only — avoids SessionLifecycleRuntime ↔ CreateId ensureLinked cycle (#9446). */
    public static function ensureRandomIdStringLinked(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        // NestedJIT SessionCreateIdJitHelper calls random_bytes() (#21900).
        StringRandomBytes::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_random_id_string', self::implementRandomIdString(...));
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

    private static function implementRandomIdString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('srid_rand_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::RANDOM_ID));
        $context->builder->returnValue($result);
    }

    private static function implementCreateIdApply(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('scid_apply_entry');
        $context->builder->positionAtEnd($entry);

        $outPtr = $fn->getParam(0);
        $prefix = $fn->getParam(1);
        $strPtr = $context->getTypeFromString('__string__*');

        // NestedJIT `?string` param/return for createIdNullable segfaults under thin
        // user-script AOT (#27258). Null/empty prefix → randomIdString(); non-empty
        // prefix → createIdWithPrefix(string) (non-nullable ABI; peer #21900 / #26773).
        $isNull = $context->builder->icmp(Builder::INT_EQ, $prefix, $strPtr->constNull());
        $bbNull = BasicBlockHelper::append($context, 'scid_apply_null');
        $bbPrefix = BasicBlockHelper::append($context, 'scid_apply_prefix');
        $context->builder->branchIf($isNull, $bbNull, $bbPrefix);

        $context->builder->positionAtEnd($bbNull);
        $nullResultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::RANDOM_ID),
            []
        );
        $nullResult = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $nullResultRaw);
        self::writeNullableStringResult($context, $fn, $outPtr, $nullResult, $nullResultRaw);

        $context->builder->positionAtEnd($bbPrefix);
        $len = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $prefix
        );
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $bbEmpty = BasicBlockHelper::append($context, 'scid_apply_empty');
        $bbNonEmpty = BasicBlockHelper::append($context, 'scid_apply_nonempty');
        $context->builder->branchIf($isEmpty, $bbEmpty, $bbNonEmpty);

        $context->builder->positionAtEnd($bbEmpty);
        $emptyResultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::RANDOM_ID),
            []
        );
        $emptyResult = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $emptyResultRaw);
        self::writeNullableStringResult($context, $fn, $outPtr, $emptyResult, $emptyResultRaw);

        $context->builder->positionAtEnd($bbNonEmpty);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::CREATE_ID_WITH_PREFIX),
            [$prefix]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        self::writeNullableStringResult($context, $fn, $outPtr, $result, $resultRaw);
    }

    private static function implementCreateIdApplyBoxed(Context $context, LlvmFunction $fn): void
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
            $i8->constInt(JITVariable::TYPE_NULL, false)
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

    private static function writeNullableStringResult(
        Context $context,
        LlvmFunction $fn,
        $outPtr,
        $result,
        $resultRaw = null
    ): void {
        $strPtr = $context->getTypeFromString('__string__*');
        $isFail = null !== $resultRaw
            ? JitNestedHelperCoerce::isHelperResultNull($context, $resultRaw)
            : $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());
        $bbFail = BasicBlockHelper::append($context, 'scid_fail');
        $bbOk = BasicBlockHelper::append($context, 'scid_ok');
        $bbDone = BasicBlockHelper::append($context, 'scid_done');
        $context->builder->branchIf($isFail, $bbFail, $bbOk);

        $context->builder->positionAtEnd($bbFail);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $result
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SessionCreateIdJitHelper compile (#9500)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21941'
        );
    }
}
