<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for session_create_id() via SessionCreateIdJitHelper PHP (#9500, #21941, #33261).
 *
 * Replaces hex-table / entropy LLVM in this file; SSOT {@see \PHPCompiler\ext\standard\VmSession}.
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ProcessRuntime #21857).
 * php-src: ext/session/session.c — php_session_create_id
 *
 * Owns create-id ABI decls module-locally (`getNamedFunction` first via
 * {@see declareSessionCreateIdAbis}) — do not re-add empty always-on shells in
 * {@see Type} (#31894 / #32122 / #33261).
 *
 * Entropy: `__compiler_random_bytes` (user-script CSPRNG) + NestedJIT `sidFromEntropy`
 * — NestedJIT `\random_bytes` inside SessionCreateIdJitHelper is still near-constant (#21900 / #33023).
 */
final class SessionCreateIdRuntime
{
    private const HELPER_PATH = '/ext/standard/SessionCreateIdJitHelper.php';

    private const RANDOM_ID = 'PHPCompiler\\ext\\standard\\SessionCreateIdJitHelper::randomIdString';

    private const CREATE_ID = 'PHPCompiler\\ext\\standard\\SessionCreateIdJitHelper::createIdNullable';

    private const CREATE_ID_WITH_PREFIX = 'PHPCompiler\\ext\\standard\\SessionCreateIdJitHelper::createIdWithPrefix';

    /** php-src default session.sid_length (#10864). */
    private const SID_LENGTH = 26;

    /** php-src default session.sid_bits_per_character (#10864). */
    private const SID_BITS_PER_CHAR = 5;

    /** php-src bin_to_readable alphabet (64 glyphs; 5-bit uses first 32). */
    private const BIN_MAP = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ,-';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RANDOM_ID,
        self::CREATE_ID,
        self::CREATE_ID_WITH_PREFIX,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_session_random_id_string',
        '__phpc_session_create_id_apply',
        '__phpc_session_create_id_apply_boxed',
    ];

    /**
     * Module-local empty decls for Type::register (#33261).
     * Bodies come from {@see ensureLinked}.
     */
    public static function declareSessionCreateIdAbis(Context $context): void
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
        // Create-id ABI only — do not pull SessionLifecycleRuntime (session_start AOT
        // NestedJIT still segfaults on master; create_id must link standalone) (#27258).
        // Save before StringRandomBytes/NestedJIT — they clear the insert block (#32994).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        SessionStorageGlobals::ensureGlobals($context);
        StringRandomBytes::ensureLinked($context);

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_random_id_string', self::implementRandomIdString(...));
        self::implementIfMissing($context, '__phpc_session_create_id_apply', self::implementCreateIdApply(...));
        self::implementIfMissing($context, '__phpc_session_create_id_apply_boxed', self::implementCreateIdApplyBoxed(...));

        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
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
        // Mid-invoke ensureLinked: loweringLlvmFunction is the user fn (#32994 / peer #27211).
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

        // User-script `__compiler_random_bytes` is honest CSPRNG; NestedJIT random_bytes /
        // binToReadable inside SessionCreateIdJitHelper corrupt entropy (#21900 / #33023).
        StringRandomBytes::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $bytes = $context->builder->call(
            $context->lookupFunction('__compiler_random_bytes'),
            $i64->constInt(self::SID_LENGTH, false)
        );
        $result = self::emitBinToReadable($context, $fn, $bytes);
        $context->builder->returnValue($result);
    }

    /**
     * php-src ext/session/session.c bin_to_readable() — sid_length=26, bits=5 (#10864 / #33023).
     *
     * @param Value $bytesStr `__string__*` of CSPRNG bytes (length >= sid_length)
     * @return Value `__string__*` readable sid
     */
    private static function emitBinToReadable(Context $context, LlvmFunction $fn, $bytesStr)
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strMap = $context->structFieldMap['__string__'];
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $outLen = $i64->constInt(self::SID_LENGTH, false);
        $bits = $i64->constInt(self::SID_BITS_PER_CHAR, false);
        $mask = $i64->constInt((1 << self::SID_BITS_PER_CHAR) - 1, false);
        $eight = $i64->constInt(8, false);

        $mapConst = $context->constantFromString(self::BIN_MAP);
        $mapPtr = $context->builder->pointerCast($mapConst, $i8p);

        $srcLen = $context->builder->load($context->builder->structGep($bytesStr, $strMap['length']));
        $srcData = $context->builder->structGep($bytesStr, $strMap['value']);
        $srcPtr = $context->builder->pointerCast($srcData, $i8p);

        $out = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $outData = $context->builder->structGep($out, $strMap['value']);
        $outPtr = $context->builder->pointerCast($outData, $i8p);
        $context->builder->store($outLen, $context->builder->structGep($out, $strMap['length']));

        $pSlot = $context->builder->alloca($i64, 1, 'b2r_p');
        $wSlot = $context->builder->alloca($i64, 1, 'b2r_w');
        $haveSlot = $context->builder->alloca($i64, 1, 'b2r_have');
        $iSlot = $context->builder->alloca($i64, 1, 'b2r_i');
        $context->builder->store($zero, $pSlot);
        $context->builder->store($zero, $wSlot);
        $context->builder->store($zero, $haveSlot);
        $context->builder->store($zero, $iSlot);

        $loopHead = $fn->appendBasicBlock('b2r_loop_head');
        $loopBody = $fn->appendBasicBlock('b2r_loop_body');
        $loopDone = $fn->appendBasicBlock('b2r_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $more = $context->builder->icmp(Builder::INT_SLT, $i, $outLen);
        $context->builder->branchIf($more, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $whileHead = $fn->appendBasicBlock('b2r_while_head');
        $whileCheckP = $fn->appendBasicBlock('b2r_while_check_p');
        $whileLoad = $fn->appendBasicBlock('b2r_while_load');
        $whileEnd = $fn->appendBasicBlock('b2r_while_end');
        $context->builder->branch($whileHead);

        $context->builder->positionAtEnd($whileHead);
        $have = $context->builder->load($haveSlot);
        $needBits = $context->builder->icmp(Builder::INT_SLT, $have, $bits);
        $context->builder->branchIf($needBits, $whileCheckP, $whileEnd);

        $context->builder->positionAtEnd($whileCheckP);
        $p = $context->builder->load($pSlot);
        $pOk = $context->builder->icmp(Builder::INT_SLT, $p, $srcLen);
        $context->builder->branchIf($pOk, $whileLoad, $whileEnd);

        $context->builder->positionAtEnd($whileLoad);
        $bytePtr = $context->builder->inBoundsGep($srcPtr, $p);
        $byte = $context->builder->load($bytePtr);
        $byteZ = $context->builder->zExt($byte, $i64);
        $w = $context->builder->load($wSlot);
        $shifted = $context->builder->shl($byteZ, $have);
        $context->builder->store($context->builder->or($w, $shifted), $wSlot);
        $context->builder->store($context->builder->add($p, $one), $pSlot);
        $context->builder->store($context->builder->add($have, $eight), $haveSlot);
        $context->builder->branch($whileHead);

        $context->builder->positionAtEnd($whileEnd);
        $w2 = $context->builder->load($wSlot);
        $idx = $context->builder->and($w2, $mask);
        $mapCharPtr = $context->builder->inBoundsGep($mapPtr, $idx);
        $mapChar = $context->builder->load($mapCharPtr);
        $outCharPtr = $context->builder->inBoundsGep($outPtr, $i);
        $context->builder->store($mapChar, $outCharPtr);
        $context->builder->store($context->builder->lShr($w2, $bits), $wSlot);
        $have2 = $context->builder->load($haveSlot);
        $context->builder->store($context->builder->sub($have2, $bits), $haveSlot);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);

        return $out;
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
        $nullResult = $context->builder->call($context->lookupFunction('phpc_session_random_id_string'));
        self::writeNullableStringResult($context, $fn, $outPtr, $nullResult);

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
        $emptyResult = $context->builder->call($context->lookupFunction('phpc_session_random_id_string'));
        self::writeNullableStringResult($context, $fn, $outPtr, $emptyResult);

        $context->builder->positionAtEnd($bbNonEmpty);
        // Prefix + CSPRNG sid in LLVM — avoid NestedJIT createIdWithPrefix → random_bytes (#33023).
        $randSid = $context->builder->call($context->lookupFunction('phpc_session_random_id_string'));
        $result = JitStringConcat::concat($context, $prefix, $randSid);
        self::writeNullableStringResult($context, $fn, $outPtr, $result);
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
