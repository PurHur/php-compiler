<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\Variable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Builder as LLVMBuilderImpl;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;
use llvm\LLVMValueRef_ptr;

/**
 * JIT/AOT embed link for __compiler_preg_* via PregJitHelper PHP (#9542, #21212, #24943).
 *
 * Helper compile: bundled {@see JitVmHelperLink::ensureCompiledBundle} (peer StringPack
 * #22842 / VariableFunctionCall #24902). Embed uses PregJitHelper + VmPreg*; thin standalone
 * AOT uses PregJitHelperThinAot + PregAotFastPath (#24115) until VmPregEngine NestedJIT
 * lands (#16075).
 * preg_replace_callback uses PHP match loop + thin LLVM callback invoke (#13736).
 * php-src: ext/pcre/php_pcre.c
 */
final class PregMatchRuntime
{
    private const HELPER_PATH = '/ext/standard/PregJitHelper.php';

    private const LAST_ERROR_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::lastError';

    private const LAST_ERROR_MSG_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::lastErrorMsg';

    private const MATCH_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchArgv';

    private const MATCH_ALL_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchAllArgv';

    private const MATCH_EX_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchExArgv';

    private const TAKE_MATCH_EX_HT = 'PHPCompiler\\ext\\standard\\PregJitHelper::takeLastMatchExHashTable';

    private const THIN_MATCH_EX_CAP_COUNT = 'PHPCompiler\\ext\\standard\\PregJitHelper::thinMatchExCapCount';

    private const THIN_MATCH_EX_CAP = 'PHPCompiler\\ext\\standard\\PregJitHelper::thinMatchExCap';

    private const THIN_SPLIT_PART_COUNT = 'PHPCompiler\\ext\\standard\\PregJitHelper::thinSplitPartCount';

    private const THIN_SPLIT_PART = 'PHPCompiler\\ext\\standard\\PregJitHelper::thinSplitPart';

    private const MATCH_ALL_EX_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::matchAllExArgv';

    private const TAKE_MATCH_ALL_EX_HT = 'PHPCompiler\\ext\\standard\\PregJitHelper::takeLastMatchAllExHashTable';

    private const THIN_MATCH_ALL_PART_COUNT = 'PHPCompiler\\ext\\standard\\PregJitHelper::thinMatchAllPartCount';

    private const THIN_MATCH_ALL_PART = 'PHPCompiler\\ext\\standard\\PregJitHelper::thinMatchAllPart';

    private const REPLACE_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::replaceArgv';

    private const REPLACE_FIND_NEXT = 'PHPCompiler\\ext\\standard\\PregJitHelper::replaceFindNext';

    private const TAKE_LAST_REPLACE_POS = 'PHPCompiler\\ext\\standard\\PregJitHelper::takeLastReplacePos';

    private const TAKE_LAST_REPLACE_BODY_LEN = 'PHPCompiler\\ext\\standard\\PregJitHelper::takeLastReplaceBodyLen';

    private const REPLACE_CALLBACK_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::replaceCallbackArgv';

    private const REPLACE_CALLBACK_ARRAY_HELPER =
        'PHPCompiler\\ext\\standard\\PregJitHelper::replaceCallbackArrayArgv';

    private const INVOKE_CALLBACK_HELPER = 'PHPCompiler\\ext\\standard\\PregCallbackInvokeJitHelper::invoke';

    private const SPLIT_HELPER = 'PHPCompiler\\ext\\standard\\PregJitHelper::splitArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LAST_ERROR_HELPER,
        self::LAST_ERROR_MSG_HELPER,
        self::MATCH_HELPER,
        self::MATCH_ALL_HELPER,
        self::MATCH_EX_HELPER,
        self::TAKE_MATCH_EX_HT,
        self::THIN_MATCH_EX_CAP_COUNT,
        self::THIN_MATCH_EX_CAP,
        self::THIN_SPLIT_PART_COUNT,
        self::THIN_SPLIT_PART,
        self::MATCH_ALL_EX_HELPER,
        self::TAKE_MATCH_ALL_EX_HT,
        self::THIN_MATCH_ALL_PART_COUNT,
        self::THIN_MATCH_ALL_PART,
        self::REPLACE_HELPER,
        self::REPLACE_FIND_NEXT,
        self::TAKE_LAST_REPLACE_POS,
        self::TAKE_LAST_REPLACE_BODY_LEN,
        self::REPLACE_CALLBACK_HELPER,
        self::REPLACE_CALLBACK_ARRAY_HELPER,
        self::SPLIT_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_preg_last_error',
        '__compiler_preg_last_error_msg',
        '__compiler_preg_match',
        '__compiler_preg_match_ex',
        '__compiler_preg_match_all',
        '__compiler_preg_match_all_ex',
        '__compiler_preg_replace',
        '__compiler_preg_replace_callback',
        '__compiler_preg_split',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        // Thin + embed: publish sg_vm_context before NestedJIT of PregJitHelper (#21212 / #17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction('__compiler_preg_match');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureRuntimeHelpers($context);
        self::ensureInvokeCallbackHelperLinked($context);
        // VmPregPattern NestedJIT calls sprintf for pattern warnings (#21212 thin AOT link).
        StringFormat::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementLastErrorBridge($context);
        self::implementLastErrorMsgBridge($context);
        self::implementI64PairBridge($context, '__compiler_preg_match', self::MATCH_HELPER);
        self::implementI64PairBridge($context, '__compiler_preg_match_all', self::MATCH_ALL_HELPER);
        self::implementMatchExBridge($context, '__compiler_preg_match_ex', self::MATCH_EX_HELPER, self::TAKE_MATCH_EX_HT, false);
        self::implementMatchExBridge($context, '__compiler_preg_match_all_ex', self::MATCH_ALL_EX_HELPER, self::TAKE_MATCH_ALL_EX_HT, true);
        self::implementReplaceBridge($context);
        self::implementReplaceCallbackBridge($context);
        self::implementSplitBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementLastErrorBridge(Context $context): void
    {
        $abiName = '__compiler_preg_last_error';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($i64, false));
        $entry = $fn->appendBasicBlock('preg_last_error_entry');
        $context->builder->positionAtEnd($entry);
        $helperFn = self::helperFunction($context, self::LAST_ERROR_HELPER);
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, []);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLastErrorMsgBridge(Context $context): void
    {
        $abiName = '__compiler_preg_last_error_msg';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($strPtr, false));
        $entry = $fn->appendBasicBlock('preg_last_error_msg_entry');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(self::helperFunction($context, self::LAST_ERROR_MSG_HELPER));
        $context->builder->returnValue(JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementI64PairBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            );
        $entry = $fn->appendBasicBlock('preg_i64_pair_entry');
        $context->builder->positionAtEnd($entry);
        $helperFn = self::helperFunction($context, $helperLogical);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMatchExBridge(
        Context $context,
        string $abiName,
        string $matchHelper,
        string $takeHtHelper,
        bool $thinMatchAll
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $negOne = $i64->constInt(-1, true);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $strPtr, $valuePtr, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('preg_match_ex_entry');
        $failBb = $fn->appendBasicBlock('preg_match_ex_fail');
        $okBb = $fn->appendBasicBlock('preg_match_ex_ok');
        $context->builder->positionAtEnd($entry);
        $count = $context->builder->call(
            self::helperFunction($context, $matchHelper),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(3),
            $fn->getParam(4)
        );
        $countI64 = JitNestedHelperCoerce::scalarToI64($context, $count, $count->typeOf());
        $isError = $context->builder->icmp(Builder::INT_EQ, $countI64, $negOne);
        $context->builder->branchIf($isError, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        // Compile failure: leave by-ref $matches untouched (#17597).
        // Past-end offset: helper stored empty HT — write it (#25313).
        $failHtRaw = $context->builder->call(self::helperFunction($context, $takeHtHelper));
        $failHt = JitNestedHelperCoerce::coerceToHashtablePtr($context, $failHtRaw);
        $failHtNull = $context->builder->icmp(Builder::INT_EQ, $failHt, $htPtr->constNull());
        $failWriteBb = $fn->appendBasicBlock('preg_match_ex_fail_write');
        $failDoneBb = $fn->appendBasicBlock('preg_match_ex_fail_done');
        $context->builder->branchIf($failHtNull, $failDoneBb, $failWriteBb);
        $context->builder->positionAtEnd($failWriteBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $fn->getParam(2),
            $failHt
        );
        $context->builder->branch($failDoneBb);
        $context->builder->positionAtEnd($failDoneBb);
        $context->builder->returnValue($negOne);

        $context->builder->positionAtEnd($okBb);
        $htRaw = $context->builder->call(self::helperFunction($context, $takeHtHelper));
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $emptyBb = $fn->appendBasicBlock('preg_match_ex_empty_ht');
        $writeBb = $fn->appendBasicBlock('preg_match_ex_write_ht');
        $context->builder->branchIf($htNull, $emptyBb, $writeBb);

        $context->builder->positionAtEnd($emptyBb);
        // Thin AOT: takeLastMatch*HashTable is always null; fill from string slots.
        // match_all: nested `$matches[0]=[…]` (#27195); match: flat caps (#24115).
        $filledHt = $thinMatchAll
            ? self::emitThinMatchAllHashtableFromParts($context, $fn)
            : self::emitThinMatchExHashtableFromCaps($context, $fn);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $fn->getParam(2),
            $filledHt
        );
        $context->builder->returnValue($countI64);

        $context->builder->positionAtEnd($writeBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $fn->getParam(2),
            $ht
        );
        $context->builder->returnValue($countI64);
        $context->registerFunction($abiName, $fn);
    }

    /**
     * Build $matches from PregJitHelper::thinMatchExCap* (NestedJIT-safe strings).
     */
    private static function emitThinMatchExHashtableFromCaps(Context $context, LlvmFunction $fn): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $capCountRaw = $context->builder->call(
            self::helperFunction($context, self::THIN_MATCH_EX_CAP_COUNT)
        );
        $capCount = JitNestedHelperCoerce::scalarToI64($context, $capCountRaw, $capCountRaw->typeOf());
        $hasCaps = $context->builder->icmp(
            Builder::INT_SGT,
            $capCount,
            $i64->constInt(0, true)
        );
        $fillBb = $fn->appendBasicBlock('preg_match_ex_thin_fill');
        $doneBb = $fn->appendBasicBlock('preg_match_ex_thin_done');
        $context->builder->branchIf($hasCaps, $fillBb, $doneBb);

        $context->builder->positionAtEnd($fillBb);
        // Bound to 8 slots (full match + up to 7 groups) for thin fast path (#26888).
        $max = 8;
        for ($i = 0; $i < $max; ++$i) {
            $idxBb = $fn->appendBasicBlock('preg_match_ex_thin_cap_'.$i);
            $skipBb = $fn->appendBasicBlock('preg_match_ex_thin_skip_'.$i);
            $need = $context->builder->icmp(
                Builder::INT_SGT,
                $capCount,
                $i64->constInt($i, true)
            );
            $context->builder->branchIf($need, $idxBb, $skipBb);

            $context->builder->positionAtEnd($idxBb);
            $capRaw = $context->builder->call(
                self::helperFunction($context, self::THIN_MATCH_EX_CAP),
                $i64->constInt($i, true)
            );
            $capStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $capRaw);
            $slot = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $capStr
            );
            HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt($i, false), $slot);
            $context->builder->branch($skipBb);

            $context->builder->positionAtEnd($skipBb);
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ht;
    }

    /**
     * Build preg_match_all $matches (PREG_PATTERN_ORDER, no groups) from thinMatchAllPart*.
     * Shape: `$matches[0] = [m0, m1, …]` when count>0; empty HT when none (#27195).
     */
    private static function emitThinMatchAllHashtableFromParts(Context $context, LlvmFunction $fn): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $outer = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $partCountRaw = $context->builder->call(
            self::helperFunction($context, self::THIN_MATCH_ALL_PART_COUNT)
        );
        $partCount = JitNestedHelperCoerce::scalarToI64(
            $context,
            $partCountRaw,
            $partCountRaw->typeOf()
        );
        $hasParts = $context->builder->icmp(
            Builder::INT_SGT,
            $partCount,
            $i64->constInt(0, true)
        );
        $fillBb = $fn->appendBasicBlock('preg_match_all_thin_fill');
        $doneBb = $fn->appendBasicBlock('preg_match_all_thin_done');
        $context->builder->branchIf($hasParts, $fillBb, $doneBb);

        $context->builder->positionAtEnd($fillBb);
        $inner = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $max = 8;
        for ($i = 0; $i < $max; ++$i) {
            $idxBb = $fn->appendBasicBlock('preg_match_all_thin_part_'.$i);
            $skipBb = $fn->appendBasicBlock('preg_match_all_thin_skip_'.$i);
            $need = $context->builder->icmp(
                Builder::INT_SGT,
                $partCount,
                $i64->constInt($i, true)
            );
            $context->builder->branchIf($need, $idxBb, $skipBb);

            $context->builder->positionAtEnd($idxBb);
            $partRaw = $context->builder->call(
                self::helperFunction($context, self::THIN_MATCH_ALL_PART),
                $i64->constInt($i, true)
            );
            $partStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $partRaw);
            $slot = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $partStr
            );
            HashTableHelper::setAtIndex($context, $inner, $sizeT->constInt($i, false), $slot);
            $context->builder->branch($skipBb);
            $context->builder->positionAtEnd($skipBb);
        }
        $row0 = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $inner
        );
        HashTableHelper::setAtIndex($context, $outer, $sizeT->constInt(0, false), $row0);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $outer;
    }

    private static function implementReplaceBridge(Context $context): void
    {
        $abiName = '__compiler_preg_replace';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        // Always use int-find + LLVM concat — NestedJIT string returns are corrupt under thin
        // AOT and sticky under HELPER_RUNTIME_O=0 (#27181). Embed also benefits.
        self::implementThinReplaceBridge($context, $probe);
    }

    /**
     * Thin AOT: NestedJIT finds match offsets (ints) on the *original* subject; LLVM
     * concatenates durable subject slices + replacement (#27181).
     * Never re-search a rebuilt haystack (StrReplace #23912 sticky-suffix peer).
     */
    private static function implementThinReplaceBridge(Context $context, ?LlvmFunction $probe): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $map = $context->structFieldMap['__string__'];
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                '__compiler_preg_replace',
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64)
            );
        $entry = $fn->appendBasicBlock('preg_replace_thin_entry');
        $zeroLimBb = $fn->appendBasicBlock('preg_replace_thin_zero_limit');
        $initBb = $fn->appendBasicBlock('preg_replace_thin_init');
        $headBb = $fn->appendBasicBlock('preg_replace_thin_head');
        $findBb = $fn->appendBasicBlock('preg_replace_thin_find');
        $failBb = $fn->appendBasicBlock('preg_replace_thin_fail');
        $matchBb = $fn->appendBasicBlock('preg_replace_thin_match');
        $restBb = $fn->appendBasicBlock('preg_replace_thin_rest');
        $doneBb = $fn->appendBasicBlock('preg_replace_thin_done');
        $context->builder->positionAtEnd($entry);

        $zeroLimit = $context->builder->icmp(Builder::INT_EQ, $fn->getParam(3), $zero);
        $context->builder->branchIf($zeroLimit, $zeroLimBb, $initBb);

        $context->builder->positionAtEnd($zeroLimBb);
        $context->builder->returnValue($fn->getParam(2));

        $context->builder->positionAtEnd($initBb);
        $offSlot = $context->builder->alloca($i64, 1, 'preg_replace_off');
        $nSlot = $context->builder->alloca($i64, 1, 'preg_replace_n');
        $outSlot = $context->builder->alloca($strPtr, 1, 'preg_replace_out');
        $context->builder->store($zero, $offSlot);
        $context->builder->store($zero, $nSlot);
        $empty = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->store($empty, $outSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $limit = $fn->getParam(3);
        $n = $context->builder->load($nSlot);
        $limitNonNeg = $context->builder->icmp(Builder::INT_SGE, $limit, $zero);
        $atLimit = $context->builder->icmp(Builder::INT_SGE, $n, $limit);
        $stopLimit = $context->builder->and($limitNonNeg, $atLimit);
        $context->builder->branchIf($stopLimit, $restBb, $findBb);

        $context->builder->positionAtEnd($findBb);
        $off = $context->builder->load($offSlot);
        // Always scan original subject param — never a rebuilt haystack (#23912 / #27181).
        $rcRaw = $context->builder->call(
            self::helperFunction($context, self::REPLACE_FIND_NEXT),
            $fn->getParam(0),
            $fn->getParam(2),
            $off
        );
        $rc = JitNestedHelperCoerce::coerceBridgeResult($context, $rcRaw, $i64);
        $isErr = $context->builder->icmp(Builder::INT_SLT, $rc, $zero);
        $isMatch = $context->builder->icmp(Builder::INT_EQ, $rc, $one);
        $afterErr = $fn->appendBasicBlock('preg_replace_thin_after_err');
        $context->builder->branchIf($isErr, $failBb, $afterErr);
        $context->builder->positionAtEnd($afterErr);
        $context->builder->branchIf($isMatch, $matchBb, $restBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($matchBb);
        $posRaw = $context->builder->call(self::helperFunction($context, self::TAKE_LAST_REPLACE_POS));
        $blenRaw = $context->builder->call(self::helperFunction($context, self::TAKE_LAST_REPLACE_BODY_LEN));
        $pos = JitNestedHelperCoerce::coerceBridgeResult($context, $posRaw, $i64);
        $blen = JitNestedHelperCoerce::coerceBridgeResult($context, $blenRaw, $i64);
        $subj = $fn->getParam(2);
        $repl = $fn->getParam(1);
        $curOff = $context->builder->load($offSlot);
        $prefixLen = $context->builder->sub($pos, $curOff);
        $replLen = $context->builder->load($context->builder->structGep($repl, $map['length']));
        $out = $context->builder->load($outSlot);
        $outLen = $context->builder->load($context->builder->structGep($out, $map['length']));
        $total = $context->builder->add(
            $context->builder->add($outLen, $prefixLen),
            $replLen
        );
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $total);
        // Peer String_::concat — structGep value (no int8* cast); re-point intrinsic builder (#2967).
        $context->intrinsic->builder = $context->builder;
        $destChar = $context->builder->structGep($dest, $map['value']);
        $outChar = $context->builder->structGep($out, $map['value']);
        $subjChar = $context->builder->structGep($subj, $map['value']);
        $replChar = $context->builder->structGep($repl, $map['value']);
        $context->intrinsic->memcpy($destChar, $outChar, $outLen, false);
        $atPrefix = $context->builder->gep($destChar, $outLen);
        $srcPrefix = $context->builder->gep($subjChar, $curOff);
        $context->intrinsic->memcpy($atPrefix, $srcPrefix, $prefixLen, false);
        $atRepl = $context->builder->gep($atPrefix, $prefixLen);
        $context->intrinsic->memcpy($atRepl, $replChar, $replLen, false);
        $context->builder->store($dest, $outSlot);
        $context->builder->store($context->builder->add($pos, $blen), $offSlot);
        $context->builder->store($context->builder->add($n, $one), $nSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($restBb);
        $subj = $fn->getParam(2);
        $subjLen = $context->builder->load($context->builder->structGep($subj, $map['length']));
        $restOff = $context->builder->load($offSlot);
        $restLen = $context->builder->sub($subjLen, $restOff);
        $out = $context->builder->load($outSlot);
        $outLen = $context->builder->load($context->builder->structGep($out, $map['length']));
        $total = $context->builder->add($outLen, $restLen);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $total);
        $context->intrinsic->builder = $context->builder;
        $destChar = $context->builder->structGep($dest, $map['value']);
        $outChar = $context->builder->structGep($out, $map['value']);
        $subjChar = $context->builder->structGep($subj, $map['value']);
        $context->intrinsic->memcpy($destChar, $outChar, $outLen, false);
        $atRest = $context->builder->gep($destChar, $outLen);
        $srcRest = $context->builder->gep($subjChar, $restOff);
        $context->intrinsic->memcpy($atRest, $srcRest, $restLen, false);
        $context->builder->store($dest, $outSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($context->builder->load($outSlot));
        $context->registerFunction('__compiler_preg_replace', $fn);
    }

    /** Embed/JIT: NestedJIT VmPregNative string return is durable under MCJIT. */
    private static function implementEmbedReplaceBridge(Context $context, ?LlvmFunction $probe): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                '__compiler_preg_replace',
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64)
            );
        $entry = $fn->appendBasicBlock('preg_replace_entry');
        $failBb = $fn->appendBasicBlock('preg_replace_fail');
        $okBb = $fn->appendBasicBlock('preg_replace_ok');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(
            self::helperFunction($context, self::REPLACE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw));
        $context->registerFunction('__compiler_preg_replace', $fn);
    }

    private static function implementReplaceCallbackBridge(Context $context): void
    {
        $abiName = '__compiler_preg_replace_callback';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $cbFnTy = $context->context->functionType($valuePtr, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $cbFnTy->pointerType(0))
            );
        $entry = $fn->appendBasicBlock('preg_replace_callback_entry');
        $failBb = $fn->appendBasicBlock('preg_replace_callback_fail');
        $okBb = $fn->appendBasicBlock('preg_replace_callback_ok');
        $context->builder->positionAtEnd($entry);
        $raw = $context->builder->call(
            self::helperFunction($context, self::REPLACE_CALLBACK_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            JitNestedHelperCoerce::ptrToI64($context, $fn->getParam(2))
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue(JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw));
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureInvokeCallbackHelperLinked(Context $context): void
    {
        $lc = \strtolower(self::INVOKE_CALLBACK_HELPER);
        if (isset($context->functions[$lc])) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $valueMap = $context->structFieldMap['__value__'];
        $cbFnTy = $context->context->functionType($valuePtr, false, $valuePtr);
        $cbPtrTy = $cbFnTy->pointerType(0);

        $fn = $context->module->addFunction(
            $lc,
            $context->context->functionType($strPtr, false, $i64, $htPtr)
        );
        $entry = $fn->appendBasicBlock('preg_invoke_callback_entry');
        $context->builder->positionAtEnd($entry);

        $cbAddr = $fn->getParam(0);
        $matches = $fn->getParam(1);
        $argSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $context->builder->store(
            $i8->constInt(\PHPCompiler\JIT\Variable::TYPE_NULL, false),
            $context->builder->structGep($argSlot, $valueMap['type'])
        );
        $argPtr = $context->builder->pointerCast($argSlot, $valuePtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $argPtr,
            $matches
        );
        $fnPtr = $context->builder->intToPtr($cbAddr, $cbPtrTy);
        $cbResult = self::emitIndirectCall($context, $cbFnTy, $fnPtr, $argPtr);
        $replStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $cbResult
        );
        $context->builder->returnValue($replStr);
        $context->registerFunction($lc, $fn);

        $root = \dirname(__DIR__, 3);
        $invokePath = $root.'/ext/standard/PregCallbackInvokeJitHelper.php';
        $real = \realpath($invokePath) ?: $invokePath;
        $context->markJitIncludedFileCompiled($real);
    }

    private static function emitIndirectCall(Context $context, $fnTy, Value $fnPtr, Value ...$args): Value
    {
        $b = $context->builder;
        if (!$b instanceof LLVMBuilderImpl) {
            throw new \LogicException('LLVM builder required for preg callback indirect call (#13736)');
        }
        $valueWrapper = $b->llvm->lib->makeArray(
            LLVMValueRef_ptr::class,
            array_map(static fn (Value $value) => $value->value, $args)
        );

        return $b->llvm->factory->value(
            $context->context,
            $b->llvm->lib->LLVMBuildCall2(
                $b->builder,
                $fnTy->type,
                $fnPtr->value,
                $valueWrapper,
                \count($args),
                ''
            )
        );
    }

    private static function implementSplitBridge(Context $context): void
    {
        $abiName = '__compiler_preg_split';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        // Thin standalone AOT: NestedJIT string-slot fill OOMs / empties (#27208).
        // Int-find (replaceFindNext) + LLVM subject slices → HT (peer preg_replace #27181).
        if ($context->isThinStandaloneAotMain()) {
            self::implementThinSplitBridge($context, $probe);
            $built = $context->module->getNamedFunction($abiName);
            if (null === $built) {
                throw new \LogicException('__compiler_preg_split missing after thin bridge (#27208)');
            }
            $context->registerFunction($abiName, $built);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false, $strPtr, $strPtr, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('preg_split_entry');
        $failBb = $fn->appendBasicBlock('preg_split_fail');
        $okBb = $fn->appendBasicBlock('preg_split_ok');
        $context->builder->positionAtEnd($entry);
        $htRaw = $context->builder->call(
            self::helperFunction($context, self::SPLIT_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $context->builder->branchIf($isNull, $failBb, $okBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    /**
     * Thin AOT preg_split: NestedJIT finds delimiter offsets (ints); LLVM copies subject
     * slices into a hashtable (#27208). Peer {@see implementThinReplaceBridge}.
     *
     * Signature: (pattern, subject, limit, flags) → __hashtable__* | null
     */
    private static function implementThinSplitBridge(Context $context, ?LlvmFunction $probe): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $map = $context->structFieldMap['__string__'];
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        // Ensure NestedJIT find helpers exist before emitting calls.
        self::helperFunction($context, self::REPLACE_FIND_NEXT);
        self::helperFunction($context, self::TAKE_LAST_REPLACE_POS);
        self::helperFunction($context, self::TAKE_LAST_REPLACE_BODY_LEN);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                '__compiler_preg_split',
                $context->context->functionType($htPtr, false, $strPtr, $strPtr, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('preg_split_thin_entry');
        $initBb = $fn->appendBasicBlock('preg_split_thin_init');
        $headBb = $fn->appendBasicBlock('preg_split_thin_head');
        $findBb = $fn->appendBasicBlock('preg_split_thin_find');
        $failBb = $fn->appendBasicBlock('preg_split_thin_fail');
        $matchBb = $fn->appendBasicBlock('preg_split_thin_match');
        $restBb = $fn->appendBasicBlock('preg_split_thin_rest');
        $trailBb = $fn->appendBasicBlock('preg_split_thin_trail');
        $doneBb = $fn->appendBasicBlock('preg_split_thin_done');

        $context->builder->positionAtEnd($entry);
        // flags != 0 unsupported on thin fast path (PREG_SPLIT_*).
        $hasFlags = $context->builder->icmp(Builder::INT_NE, $fn->getParam(3), $zero);
        $context->builder->branchIf($hasFlags, $failBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $offSlot = $context->builder->alloca($i64, 1, 'preg_split_off');
        $nSlot = $context->builder->alloca($i64, 1, 'preg_split_n');
        $htSlot = $context->builder->alloca($htPtr, 1, 'preg_split_ht');
        $context->builder->store($zero, $offSlot);
        $context->builder->store($zero, $nSlot);
        $ht0 = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($ht0, $htSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $limit = $fn->getParam(2);
        $n = $context->builder->load($nSlot);
        $limitPos = $context->builder->icmp(Builder::INT_SGT, $limit, $zero);
        $atLimit = $context->builder->icmp(
            Builder::INT_SGE,
            $context->builder->add($n, $one),
            $limit
        );
        $stopLimit = $context->builder->and($limitPos, $atLimit);
        $context->builder->branchIf($stopLimit, $restBb, $findBb);

        $context->builder->positionAtEnd($findBb);
        $off = $context->builder->load($offSlot);
        // ABI: (pattern, subject, limit, flags) — subject is param 1.
        $rcRaw = $context->builder->call(
            self::helperFunction($context, self::REPLACE_FIND_NEXT),
            $fn->getParam(0),
            $fn->getParam(1),
            $off
        );
        $rc = JitNestedHelperCoerce::coerceBridgeResult($context, $rcRaw, $i64);
        $isErr = $context->builder->icmp(Builder::INT_SLT, $rc, $zero);
        $isMatch = $context->builder->icmp(Builder::INT_EQ, $rc, $one);
        $afterErr = $fn->appendBasicBlock('preg_split_thin_after_err');
        $context->builder->branchIf($isErr, $failBb, $afterErr);
        $context->builder->positionAtEnd($afterErr);
        $context->builder->branchIf($isMatch, $matchBb, $restBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($matchBb);
        $posRaw = $context->builder->call(self::helperFunction($context, self::TAKE_LAST_REPLACE_POS));
        $blenRaw = $context->builder->call(self::helperFunction($context, self::TAKE_LAST_REPLACE_BODY_LEN));
        $pos = JitNestedHelperCoerce::coerceBridgeResult($context, $posRaw, $i64);
        $blen = JitNestedHelperCoerce::coerceBridgeResult($context, $blenRaw, $i64);
        $subj = $fn->getParam(1);
        $curOff = $context->builder->load($offSlot);
        $partLen = $context->builder->sub($pos, $curOff);
        $partStr = self::emitThinSplitSubjectSlice($context, $fn, $subj, $curOff, $partLen);
        $ht = $context->builder->load($htSlot);
        $nCur = $context->builder->load($nSlot);
        $idx = $sizeT === $i64 ? $nCur : $context->builder->trunc($nCur, $sizeT);
        $partVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $partStr
        );
        HashTableHelper::setAtIndex($context, $ht, $idx, $partVar);
        $nextOff = $context->builder->add($pos, $blen);
        $context->builder->store($nextOff, $offSlot);
        $context->builder->store($context->builder->add($nCur, $one), $nSlot);
        $subjLen = $context->builder->load($context->builder->structGep($subj, $map['length']));
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $nextOff, $subjLen);
        $context->builder->branchIf($atEnd, $trailBb, $headBb);

        $context->builder->positionAtEnd($trailBb);
        $empty = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $htT = $context->builder->load($htSlot);
        $nT = $context->builder->load($nSlot);
        $idxT = $sizeT === $i64 ? $nT : $context->builder->trunc($nT, $sizeT);
        $emptyVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $empty
        );
        HashTableHelper::setAtIndex($context, $htT, $idxT, $emptyVar);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($restBb);
        $subjR = $fn->getParam(1);
        $offR = $context->builder->load($offSlot);
        $subjLenR = $context->builder->load($context->builder->structGep($subjR, $map['length']));
        $restLen = $context->builder->sub($subjLenR, $offR);
        $restStr = self::emitThinSplitSubjectSlice($context, $fn, $subjR, $offR, $restLen);
        $htR = $context->builder->load($htSlot);
        $nR = $context->builder->load($nSlot);
        $idxR = $sizeT === $i64 ? $nR : $context->builder->trunc($nR, $sizeT);
        $restVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $restStr
        );
        HashTableHelper::setAtIndex($context, $htR, $idxR, $restVar);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($context->builder->load($htSlot));
    }

    /** Copy subject[start, start+len) into a new __string__ (empty when len<=0). */
    private static function emitThinSplitSubjectSlice(
        Context $context,
        LlvmFunction $fn,
        Value $subj,
        Value $start,
        Value $sliceLen
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $map = $context->structFieldMap['__string__'];
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $sliceLen, $zero);
        $emptyBb = $fn->appendBasicBlock('preg_split_slice_empty');
        $copyBb = $fn->appendBasicBlock('preg_split_slice_copy');
        $doneBb = $fn->appendBasicBlock('preg_split_slice_done');
        $context->builder->branchIf($isEmpty, $emptyBb, $copyBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($copyBb);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $sliceLen);
        $context->intrinsic->builder = $context->builder;
        $destChar = $context->builder->structGep($dest, $map['value']);
        $subjChar = $context->builder->structGep($subj, $map['value']);
        $src = $context->builder->gep($subjChar, $start);
        $context->intrinsic->memcpy($destChar, $src, $sliceLen, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($dest->typeOf());
        $phi->addIncoming($emptyStr, $emptyBb);
        $phi->addIncoming($dest, $copyBb);

        return $phi;
    }

    /**
     * Build split result from PregJitHelper::thinSplitPart* (NestedJIT-safe strings, #27080).
     * Retained for embed/tests; thin AOT uses {@see implementThinSplitBridge} (#27208).
     */
    private static function emitThinSplitHashtableFromParts(
        Context $context,
        LlvmFunction $fn,
        Value $partCount
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $max = 8;
        for ($i = 0; $i < $max; ++$i) {
            $idxBb = $fn->appendBasicBlock('preg_split_thin_part_'.$i);
            $skipBb = $fn->appendBasicBlock('preg_split_thin_skip_'.$i);
            $need = $context->builder->icmp(
                Builder::INT_SGT,
                $partCount,
                $i64->constInt($i, true)
            );
            $context->builder->branchIf($need, $idxBb, $skipBb);

            $context->builder->positionAtEnd($idxBb);
            $partRaw = $context->builder->call(
                self::helperFunction($context, self::THIN_SPLIT_PART),
                $i64->constInt($i, true)
            );
            $partStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $partRaw);
            $slot = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $partStr
            );
            HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt($i, false), $slot);
            $context->builder->branch($skipBb);
            $context->builder->positionAtEnd($skipBb);
        }

        return $ht;
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PregJitHelper compile (#9542)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // Thin standalone AOT: VmPregPure NestedJIT still hits CFG/property gaps (#16075).
        // Use PregJitHelperThinAot (fast paths, no Native→Pure) to avoid AOT segfault (#24115).
        // JIT/embed keeps PregJitHelper + Native (Pure resolves under MCJIT).
        // Skip helper-runtime cache on thin path — cached PregJitHelper is the full Native
        // TU and silently returns 0 for matches under thin AOT (#26888).
        $thin = $context->isThinStandaloneAotMain();
        $bundle = $thin
            ? [
                '/ext/standard/StdlibConstants.php',
                '/ext/standard/PregAotFastPath.php',
                '/ext/standard/PregJitHelperThinAot.php',
            ]
            : [
                '/ext/standard/StdlibConstants.php',
                '/ext/standard/PregAotFastPath.php',
                '/ext/standard/VmPregPattern.php',
                '/ext/standard/VmPregNative.php',
                '/ext/standard/VmPregMatches.php',
                self::HELPER_PATH,
            ];
        foreach (['add', 'updateindex', 'append'] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($context, $htMethod);
        }
        foreach (['null', 'int', 'string', 'array'] as $varMethod) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $varMethod);
        }
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            $bundle,
            self::COMPILED_HELPERS,
            '#24943',
            $thin
        );
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        foreach ([
            ['__value__writeHashtable', $void, [$valuePtr, $htPtr]],
            ['__hashtable__alloc', $htPtr, []],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction($name, $context->context->functionType($ret, false, ...$params));
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after PregMatchRuntime bridge (#9542)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
