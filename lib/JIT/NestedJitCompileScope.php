<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;

/**
 * Isolate CFG block maps and operand variable bindings during nested php-in-PHP JIT helper compiles (#8559, #9091, #10343, #17737).
 *
 * Also clears outer emit-helper / self-host stub env so NestedJIT lowers real helper bodies
 * (e.g. VmUrlRewriterOb during RewriteVarsRuntime — #21965, peer SELFHOST_AOT clear).
 *
 * Restore must use {@see BasicBlockHelper::restoreInsertBlock}: `positionAtEnd` on a sealed
 * outer block leaves later emits as parentless / terminator-in-middle IR (Runtime::parse
 * host-lower under M5 argv — #26756).
 */
final class NestedJitCompileScope
{
    private static int $depth = 0;

    /** @var list<string> */
    private const CLEAR_STUB_ENV_KEYS = [
        'PHP_COMPILER_SELFHOST_AOT',
        'PHP_COMPILER_EMIT_HELPER_LINK',
    ];

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Re-attach named scope slots after nested helper compiles (#17954).
     */
    public static function resyncNamedBindings(Context $context): void
    {
        foreach ($context->namedVariableBindings as $name => $var) {
            $context->bindVariableByName($name, $var);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $compile
     *
     * @return T
     */
    public static function run(Context $context, callable $compile)
    {
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $savedBlockStorage = $context->scope->blockStorage;
        $savedBlockEntryStorage = $context->scope->blockEntryStorage;
        $savedVariables = $context->scope->variables;
        $savedNamedBindings = $context->namedVariableBindings;
        // Nested helper compile sets scope->className (e.g. CaseCompareJitHelper); leaking it
        // makes later user-script $obj->method() resolve against the helper class (#22680 AOT).
        $savedClassName = $context->scope->className;
        // Nested helper method entry also overwrites jitEnclosingBlock; leaking it makes
        // asymmetric set Errors report the helper class instead of global scope (#26873).
        $savedJitEnclosingBlock = $context->jitEnclosingBlock;
        // get_defined_vars() reads jitCurrentBlock for {main} auto-globals (#30779).
        $savedJitCurrentBlock = $context->jitCurrentBlock;
        $savedJitPropertyHookRawProperty = $context->jitPropertyHookRawProperty;
        // Mid-call NestedJIT (e.g. CallUnpackRuntime → ListUnpackJitHelper) mutates toCall/args.
        // FUNCCALL_EXEC re-reads scope->toCall after resolveJitOutgoingCall; without restore the
        // packed spread hashtable is invoked as valueBoxIsArray(int) (#23971 e08_spread).
        $savedToCall = $context->scope->toCall;
        $savedArgs = $context->scope->args;
        $savedArgOperands = $context->scope->argOperands;
        $savedMagicCallMethodName = $context->scope->magicCallMethodName;
        $savedMagicCallIsStatic = $context->scope->magicCallIsStatic;
        $savedPreserveNewResultOnNullCall = $context->scope->preserveNewResultOnNullCall;
        // Nested helper compile of ErrorSilence/etc. can mutate tryCatch->handlerStack while
        // lowering inside an outer try — DEP then loses catchable ValueError (#22680).
        $savedHandlerStack = $context->tryCatch->handlerStack;
        // Foreach alloca maps are keyed by Variable id but the LLVM slots belong to the
        // function that created them. Reusing an outer (or sibling-helper) i64 index slot
        // after clearInsertionPosition() → entryAlloca via activeFunction yields
        // "Instruction does not dominate all uses" on ArrayUserSetOpsJitHelper (#28053).
        $savedForeachIndexSlots = $context->foreachIndexSlots;
        $savedForeachObjNodeSlots = $context->foreachObjNodeSlots;
        $savedForeachIteratorReceiverSlots = $context->foreachIteratorReceiverSlots;
        $savedForeachIteratorAdvanceSlots = $context->foreachIteratorAdvanceSlots;
        $savedForeachDatePeriodSnapshotHts = $context->foreachDatePeriodSnapshotHts;
        $savedForeachAggregateInnerHtSlots = $context->foreachAggregateInnerHtSlots;
        $context->scope->blockStorage = new \SplObjectStorage();
        $context->scope->blockEntryStorage = new \SplObjectStorage();
        $context->scope->variables = new \SplObjectStorage();
        $context->namedVariableBindings = [];
        $context->scope->toCall = null;
        $context->scope->args = [];
        $context->scope->argOperands = [];
        $context->scope->magicCallMethodName = null;
        $context->scope->magicCallIsStatic = false;
        $context->scope->preserveNewResultOnNullCall = false;
        $context->foreachIndexSlots = [];
        $context->foreachObjNodeSlots = [];
        $context->foreachIteratorReceiverSlots = [];
        $context->foreachIteratorAdvanceSlots = [];
        $context->foreachDatePeriodSnapshotHts = [];
        $context->foreachAggregateInnerHtSlots = [];
        // Drop outer activeFunction while insert is cleared — otherwise parentFunction() /
        // entryAlloca pin allocas into the outer fn and NestedJIT bodies load them (#28053).
        $context->activeFunction = '';
        $prevStubEnv = self::clearStubEnvForNestedHelperCompile();
        try {
            $context->builder->clearInsertionPosition();
            ++self::$depth;

            return $compile();
        } finally {
            --self::$depth;
            $context->scope->blockStorage = $savedBlockStorage;
            $context->scope->blockEntryStorage = $savedBlockEntryStorage;
            $context->scope->variables = $savedVariables;
            $context->namedVariableBindings = $savedNamedBindings;
            $context->scope->className = $savedClassName;
            $context->jitEnclosingBlock = $savedJitEnclosingBlock;
            $context->jitCurrentBlock = $savedJitCurrentBlock;
            $context->jitPropertyHookRawProperty = $savedJitPropertyHookRawProperty;
            $context->scope->toCall = $savedToCall;
            $context->scope->args = $savedArgs;
            $context->scope->argOperands = $savedArgOperands;
            $context->scope->magicCallMethodName = $savedMagicCallMethodName;
            $context->scope->magicCallIsStatic = $savedMagicCallIsStatic;
            $context->scope->preserveNewResultOnNullCall = $savedPreserveNewResultOnNullCall;
            $context->tryCatch->handlerStack = $savedHandlerStack;
            $context->foreachIndexSlots = $savedForeachIndexSlots;
            $context->foreachObjNodeSlots = $savedForeachObjNodeSlots;
            $context->foreachIteratorReceiverSlots = $savedForeachIteratorReceiverSlots;
            $context->foreachIteratorAdvanceSlots = $savedForeachIteratorAdvanceSlots;
            $context->foreachDatePeriodSnapshotHts = $savedForeachDatePeriodSnapshotHts;
            $context->foreachAggregateInnerHtSlots = $savedForeachAggregateInnerHtSlots;
            self::resyncNamedBindings($context);
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
            self::restoreStubEnv($prevStubEnv);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private static function clearStubEnvForNestedHelperCompile(): array
    {
        $prev = [];
        if (!\function_exists('putenv')) {
            return $prev;
        }
        foreach (self::CLEAR_STUB_ENV_KEYS as $key) {
            $prev[$key] = \getenv($key);
            \putenv($key.'=0');
        }

        return $prev;
    }

    /**
     * @param array<string, string|false> $prev
     */
    private static function restoreStubEnv(array $prev): void
    {
        if (!\function_exists('putenv')) {
            return;
        }
        foreach ($prev as $key => $value) {
            if (false === $value || null === $value) {
                \putenv($key.'=');
            } else {
                \putenv($key.'='.$value);
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        return BasicBlockHelper::tryGetInsertBlock($context);
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        BasicBlockHelper::restoreInsertBlock($context, $block);
    }
}
