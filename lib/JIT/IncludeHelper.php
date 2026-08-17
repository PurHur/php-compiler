<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\ext\standard\IncludeBindingJitHelper;
use PHPCompiler\ext\standard\IncludeJitHelper;
use PHPCompiler\Web\DeployRoot;

/**
 * Compile-time literal include/require for JIT/AOT (issue #54, #475, #485).
 */
final class IncludeHelper
{
    private static int $includeEntrySerial = 0;

    public static function compileLiteral(
        JIT $jit,
        Function_ $func,
        Block $callerBlock,
        OpCode $op,
        ?Operand $resultOperand
    ): void {
        $context = $jit->context;
        if (null !== $op->arg3 && isset($callerBlock->deployIncludePaths[$op->arg3])) {
            self::compileDeployPathInclude($jit, $func, $callerBlock, $op, $resultOperand);

            return;
        }
        $path = null;
        if (null !== $op->arg3 && isset($callerBlock->literalIncludePaths[$op->arg3])) {
            $path = $callerBlock->literalIncludePaths[$op->arg3];
        }
        if (null === $path) {
            $pathOperand = $callerBlock->getOperand($op->arg1);
            $path = IncludeJitHelper::resolveLiteralPath($callerBlock, $op->arg1, $pathOperand, $context);
        }
        if (null === $path || '' === $path) {
            if (IncludeJitHelper::shouldStubM3SidecarHostNonLiteralInclude($callerBlock)) {
                self::emitSkippedSelfHostSpineCliInclude($jit, $callerBlock, $resultOperand);

                return;
            }
            $caller = $callerBlock->scriptPath();
            $operandDesc = get_debug_type($pathOperand);
            $name = OperandName::resolve($pathOperand);
            if (null !== $name && '' !== $name) {
                $operandDesc .= ' '.$name;
            }
            throw new \LogicException(
                'include/require must use a compile-time literal path for JIT/AOT (issue #54)'
                .' — caller='.$caller
                .' operand='.$operandDesc
            );
        }
        if (IncludeJitHelper::shouldSkipSelfHostSpineCliInclude($path)) {
            self::emitSkippedSelfHostSpineCliInclude($jit, $callerBlock, $resultOperand);

            return;
        }
        self::compileIncludedFile($jit, $func, $callerBlock, $path, $resultOperand);
    }

    private static function compileIncludedFile(
        JIT $jit,
        Function_ $func,
        Block $callerBlock,
        string $path,
        ?Operand $resultOperand
    ): void {
        $context = $jit->context;
        if (!is_file($path)) {
            throw new \LogicException('include file not found for JIT/AOT: '.$path);
        }

        if ($context->hasJitIncludedFileCompiled($path)) {
            $context->recordJitIncludedFile($path);
            if (null !== $resultOperand) {
                $jit->assignIncludeResult($resultOperand);
            }

            return;
        }

        $context->recordJitIncludedFile($path);

        $included = $context->runtime->parseAndCompileFile($path, true);
        if (null === $included) {
            $diag = $context->runtime->compiler->getCompileAbortDetail();
            $suffix = null !== $diag && '' !== $diag ? ' — '.$diag : ' — (no compiler abort detail; parser/CFG returned null)';
            throw new \LogicException('failed to compile include: '.$path.$suffix);
        }

        $context->markJitIncludedFileCompiled($path);

        self::compileInlinedBlock($jit, $func, $callerBlock, $included, $resultOperand, false, 'c:include:'.$path);
    }

    /**
     * Inline a compiled block in caller scope (include/require or eval, issue #4652).
     */
    public static function compileInlinedBlock(
        JIT $jit,
        Function_ $func,
        Block $callerBlock,
        Block $included,
        ?Operand $resultOperand,
        bool $captureEvalReturn,
        string $progressNote = 'c:inline'
    ): void {
        $context = $jit->context;

        // Remap colliding parent slots — callee opcodes keep their indices; parent
        // locals are name-bound via IncludeBindingJitHelper (#22845 MiniWebApp).
        // Zend full-spine may opt out via PHP_COMPILER_INCLUDE_SCOPE_REMAP=0: always-on
        // remapping made honest gen-0 refresh ~25× slower (r14 vs r13 @ gmp, #22642).
        $remapCollidingSlots = true;
        $remapFlag = getenv('PHP_COMPILER_INCLUDE_SCOPE_REMAP');
        if (is_string($remapFlag)) {
            $remapLc = strtolower($remapFlag);
            if ('0' === $remapFlag || 'false' === $remapLc || 'off' === $remapLc) {
                $remapCollidingSlots = false;
            }
        }
        $included->inheritScopeFrom($callerBlock, $remapCollidingSlots);
        $included->inheritUndefinedLocals = true;

        $context->inlineIncludeCallerBlocks[] = $callerBlock;
        // Bind from the immediate caller TU. Nested layout→partial sees names the
        // layout already inherited from the defining method (#764). Skipping to the
        // grandparent re-resolved defining-TU __value__ slots and could surface a
        // sibling local ($title) as $appName in the partial (#22845).
        $bindingCaller = $callerBlock;
        if (null !== $context->listUnpackAssignCallerBlock) {
            $bindingCaller = $context->listUnpackAssignCallerBlock;
        }
        $localBindings = IncludeBindingJitHelper::collectCalleeLocalBindings($context, $bindingCaller, $included);
        $preIncludeBb = $context->builder->getInsertBlock();
        $preparedBindings = new \SplObjectStorage();
        $context->inlineIncludeBindingRefreshStack[] = [];
        $bindingRefreshIndex = \count($context->inlineIncludeBindingRefreshStack) - 1;
        $returnHolderOp = new Temporary();
        $entryBb = $func->appendBasicBlock('include_entry_'.(++self::$includeEntrySerial));
        if (null !== $preIncludeBb && null === $preIncludeBb->getTerminator()) {
            $context->builder->positionAtEnd($preIncludeBb);
            $context->builder->branch($entryBb);
        }
        $context->builder->positionAtEnd($entryBb);
        $returnHolder = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            JitValueBox::alloc($context)
        );
        if ($captureEvalReturn) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $returnHolder->value)
            );
        } else {
            JitValueBox::writeLong($context, $returnHolder->value, $context->constantFromInteger(1));
        }
        $context->setVariableOp($returnHolderOp, $returnHolder);
        $context->inlineIncludeReturnOperands[] = $returnHolderOp;
        // Best-effort breadcrumb for self-host segfault triage: many bootstrap bundles are pure include
        // spines; record which include boundary we last entered before a fatal crash.
        Progress::emitNativeNote($context, $progressNote);
        // Materialize inherited locals at include_entry so if/elseif arms that assign
        // from $_REQUEST before this include are visible (#764, #747).
        IncludeBindingJitHelper::syncLocalBindingsFromScope($context, $localBindings, $bindingCaller);
        foreach ($localBindings as $operand) {
            $bindingName = OperandName::resolve($operand);
            $resolvedCaller = IncludeBindingJitHelper::resolveIncludeCallerVar(
                $context,
                $bindingName,
                $localBindings[$operand],
                $bindingCaller
            );
            $localBindings[$operand] = $resolvedCaller;
            $preparedBindings[$operand] = IncludeBindingEmitHelper::prepareCallerBinding(
                $context,
                $entryBb,
                $resolvedCaller,
                $bindingName
            );
        }

        $context->pushScope();
        ++$context->inlineIncludeDepth;
        $context->builder->positionAtEnd($entryBb);
        self::bindIncludedThis($context, $included, $entryBb);
        foreach ($localBindings as $operand) {
            if (!$context->hasVariableOp($operand)) {
                $calleeVar = new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VARIABLE,
                    BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'))
                );
                $calleeVar->initialize();
                $context->setVariableOp($operand, $calleeVar);
            }
        }
        $context->builder->positionAtEnd($entryBb);
        foreach ($localBindings as $operand) {
            $bindingName = OperandName::resolve($operand);
            $resolvedCaller = $localBindings[$operand];
            IncludeBindingEmitHelper::emitCalleeLocalBinding(
                $context,
                $jit,
                $operand,
                $preparedBindings[$operand]
            );
            $compileTimeString = null;
            if (
                null !== $bindingName
                && !IncludeBindingJitHelper::hasMultipleAssignsInCaller($bindingCaller, $bindingName)
            ) {
                $literal = IncludeBindingJitHelper::variableFromCallerAssignConstant($context, $bindingCaller, $bindingName);
                if (
                    null !== $literal
                    && null !== $literal->compileTimeString
                    && Variable::TYPE_STRING === $resolvedCaller->type
                    && null !== $resolvedCaller->compileTimeString
                    && $resolvedCaller->compileTimeString === $literal->compileTimeString
                ) {
                    $compileTimeString = $literal->compileTimeString;
                }
            }
            $calleeSnapshot = $context->scope->variables->contains($operand)
                ? $context->scope->variables[$operand]
                : $context->getVariableFromOp($operand);
            if (
                null === $bindingName
                || !IncludeBindingJitHelper::callerDeclaresLocalName($bindingCaller, $bindingName)
            ) {
                continue;
            }
            $context->inlineIncludeBindingRefreshStack[$bindingRefreshIndex][] = [
                $operand,
                $preparedBindings[$operand],
                $calleeSnapshot,
                $compileTimeString,
            ];
        }
        $bodyBb = $func->appendBasicBlock('include_body_'.(++self::$includeEntrySerial));
        // Bindings may end in copyFromPointer tails; entryBb can already have a terminator (#776).
        $bindTail = $context->builder->getInsertBlock();
        if (null !== $bindTail && null === $bindTail->getTerminator()) {
            $context->builder->positionAtEnd($bindTail);
            $context->builder->branch($bodyBb);
        } elseif (null === $entryBb->getTerminator()) {
            $context->builder->positionAtEnd($entryBb);
            $context->builder->branch($bodyBb);
        }
        try {
            $context->inlineIncludeExitBlock = null;
            $exitBb = $jit->compileIncludedAtEntry($func, $included, $bodyBb);
        } finally {
            --$context->inlineIncludeDepth;
            $context->popScope();
            array_pop($context->inlineIncludeCallerBlocks);
            array_pop($context->inlineIncludeBindingRefreshStack);
            // Nested includes must not leak exit blocks to the outer TU (#764, #878).
            $context->inlineIncludeExitBlock = null;
        }

        $resumeBb = self::appendIncludeResume($context, $func);
        $context->builder->positionAtEnd($exitBb);
        if (null === $exitBb->getTerminator()) {
            $context->builder->branch($resumeBb);
        }
        $context->builder->positionAtEnd($resumeBb);
        // Caller opcodes after this include must lower into resumeBb, not preIncludeBb (#475).
        if ($context->scope->blockStorage->contains($callerBlock)) {
            $context->scope->blockStorage[$callerBlock] = $resumeBb;
        }

        if (null !== $resultOperand) {
            $jit->assignIncludeResult($resultOperand);
        }
        array_pop($context->inlineIncludeReturnOperands);
    }

    /**
     * Inline deploy-path includes using the compile-tree file; runtime PHPC_DEPLOY_ROOT is VM-only until #623 AOT runtime hook.
     */
    private static function compileDeployPathInclude(
        JIT $jit,
        Function_ $func,
        Block $callerBlock,
        OpCode $op,
        ?Operand $resultOperand
    ): void {
        $context = $jit->context;
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        \PHPCompiler\JIT\Builtin\StringDeployPath::ensureStandaloneBodies($context);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'deploy_include_cont');
        }
        $spec = $callerBlock->deployIncludePaths[$op->arg3];
        $path = $spec['compile'];
        if (null === $path) {
            $path = DeployRoot::resolvePathWithSuffix($spec['rel'], $spec['fallback'], $spec['suffix']);
        }
        if (null === $path || '' === $path || !is_file($path)) {
            throw new \LogicException(
                'deploy-path include file not found for JIT/AOT (issue #623): '
                .$spec['rel'].$spec['suffix']
            );
        }
        self::compileIncludedFile($jit, $func, $callerBlock, $path, $resultOperand);
    }

    /**
     * ZEND_INCLUDE_OR_EVAL copies EX(This) into the inlined {main} (#31902 / #31903).
     *
     * Alias inlined {main} `$this` operands to the caller's LLVM `$this`
     * (KIND_VALUE `__object__*`). Static / file-scope callers have no bound `$this`.
     */
    private static function bindIncludedThis(Context $context, Block $included, BasicBlock $entryBb): void
    {
        if (null !== $included->func && (($included->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return;
        }
        $callerThis = $context->findThisVariable();
        if (null === $callerThis || Variable::TYPE_OBJECT !== $callerThis->type || null === $callerThis->value) {
            return;
        }
        $thisOps = [];
        foreach ($included->scopedOperands() as $operand) {
            if ('this' === OperandName::resolve($operand)) {
                $thisOps[spl_object_id($operand)] = $operand;
            }
        }
        foreach ($included->argOperands() as $operand) {
            if ('this' === OperandName::resolve($operand)) {
                $thisOps[spl_object_id($operand)] = $operand;
            }
        }
        if (null !== $included->orig) {
            foreach ($included->orig->hoistedOperands as $operand) {
                if ('this' === OperandName::resolve($operand)) {
                    $thisOps[spl_object_id($operand)] = $operand;
                }
            }
        }
        if ([] === $thisOps) {
            return;
        }
        $objVar = $callerThis;
        if (Variable::KIND_VALUE !== $callerThis->kind) {
            $saved = $context->builder->getInsertBlock();
            $context->builder->positionAtEnd($entryBb);
            $loaded = $context->builder->load($callerThis->value);
            $objVar = new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $loaded
            );
            if (null !== $saved) {
                BasicBlockHelper::restoreInsertBlock($context, $saved);
            }
        }
        foreach ($thisOps as $operand) {
            $context->setVariableOp($operand, $objVar);
        }
        $context->bindVariableByName('this', $objVar);
    }

    private static function appendIncludeResume(Context $context, Function_ $func): BasicBlock
    {
        return $func->appendBasicBlock('include_resume_'.(++self::$includeEntrySerial));
    }

    public static function refreshInlineIncludeBindings(Context $context): void
    {
        IncludeBindingEmitHelper::refreshInlineIncludeBindings($context);
    }

    private static function emitSkippedSelfHostSpineCliInclude(
        JIT $jit,
        Block $callerBlock,
        ?Operand $resultOperand
    ): void {
        if (null === $resultOperand) {
            return;
        }
        // assignOperand() is private; helpers must use assignOperandForced (#21905 gen-0 refresh).
        $jit->assignOperandForced(
            $resultOperand,
            new Variable(
                $jit->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $jit->context->constantFromInteger(IncludeJitHelper::skippedSelfHostIncludeReturnInt())
            )
        );
    }
}
