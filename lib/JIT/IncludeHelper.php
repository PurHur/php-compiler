<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPLLVM\BasicBlock;
use PHPTypes\Type;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Web\Superglobals;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
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
            $path = self::resolveLiteralPath($callerBlock, $op->arg1, $pathOperand, $context);
        }
        if (null === $path || '' === $path) {
            if (self::shouldStubM3SidecarHostNonLiteralInclude($callerBlock)) {
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
        if (self::shouldSkipSelfHostSpineCliInclude($path)) {
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

        $included = $context->runtime->parseAndCompileFile($path);
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

        $included->inheritScopeFrom($callerBlock);
        $included->inheritUndefinedLocals = true;

        $context->inlineIncludeCallerBlocks[] = $callerBlock;
        $bindingCaller = $callerBlock;
        if (null !== $context->listUnpackAssignCallerBlock) {
            $bindingCaller = $context->listUnpackAssignCallerBlock;
        } elseif (\count($context->inlineIncludeCallerBlocks) > 1) {
            $bindingCaller = $context->inlineIncludeCallerBlocks[
                \count($context->inlineIncludeCallerBlocks) - 2
            ];
        }
        $localBindings = self::collectCalleeLocalBindings($context, $bindingCaller, $included);
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
        self::syncLocalBindingsFromScope($context, $localBindings, $bindingCaller);
        foreach ($localBindings as $operand) {
            $bindingName = OperandName::resolve($operand);
            $resolvedCaller = self::resolveIncludeCallerVar(
                $context,
                $bindingName,
                $localBindings[$operand],
                $bindingCaller
            );
            $localBindings[$operand] = $resolvedCaller;
            $preparedBindings[$operand] = self::prepareCallerBinding(
                $context,
                $entryBb,
                $resolvedCaller,
                $bindingName
            );
        }

        $context->pushScope();
        ++$context->inlineIncludeDepth;
        $context->builder->positionAtEnd($entryBb);
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
            self::emitCalleeLocalBinding(
                $context,
                $jit,
                $operand,
                $preparedBindings[$operand]
            );
            $compileTimeString = null;
            if (
                null !== $bindingName
                && !self::hasMultipleAssignsInCaller($bindingCaller, $bindingName)
            ) {
                $literal = self::variableFromCallerAssignConstant($context, $bindingCaller, $bindingName);
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
                || !self::callerDeclaresLocalName($bindingCaller, $bindingName)
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
     * Zend include/require: callee reads caller locals by variable name (issue #471).
     *
     * @return \SplObjectStorage
     */
    private static function collectCalleeLocalBindings(
        Context $context,
        Block $callerBlock,
        Block $includedBlock
    ): \SplObjectStorage {
        $bindings = new \SplObjectStorage();
        $calleeOps = [];
        foreach ($includedBlock->scopedOperands() as $operand) {
            $calleeOps[spl_object_id($operand)] = $operand;
        }
        foreach ($includedBlock->argOperands() as $operand) {
            $calleeOps[spl_object_id($operand)] = $operand;
        }
        foreach ($calleeOps as $operand) {
            $name = OperandName::resolve($operand);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if (!self::callerDeclaresLocalName($callerBlock, $name)) {
                continue;
            }
            $callerVar = self::callerVariableForName($context, $callerBlock, $name);
            if (null !== $callerVar) {
                $bindings[$operand] = $callerVar;
            }
        }

        return $bindings;
    }

    /**
     * {@see __value__*} for {@see __value__readString} without boxing ephemeral rvalues twice (#846).
     */
    private static function valueStringSourcePtr(Context $context, Variable $callerVar): \PHPLLVM\Value
    {
        if (Variable::TYPE_VALUE === $callerVar->type && Variable::KIND_VARIABLE === $callerVar->kind) {
            $llvmType = $context->getStringFromType($callerVar->value->typeOf());
            if ('__value__*' === $llvmType || '__value__' === $llvmType) {
                return JitValueBox::normalizeValuePtr(
                    $context,
                    JitValueBox::pointer($context, $callerVar->value)
                );
            }
        }

        return JitValueBox::valuePtrFromVariable($context, $callerVar);
    }

    /**
     * Read boxed caller locals while preIncludeBb still dominates the caller alloca (#784).
     */
    private static function prepareCallerBinding(
        Context $context,
        BasicBlock $materializeBb,
        Variable $callerVar,
        ?string $name = null
    ): Variable {
        if (
            null !== $name
            && Variable::TYPE_VALUE === $callerVar->type
            && Variable::KIND_VALUE === $callerVar->kind
        ) {
            $slotBlock = $context->listUnpackAssignRootBlock ?? $context->jitEnclosingBlock;
            if (null !== $slotBlock) {
                $stable = self::stableCallerValueSlot($context, $slotBlock, $name);
                if (null !== $stable) {
                    $callerVar = $stable;
                }
            }
            if (Variable::KIND_VALUE === $callerVar->kind) {
                $resolved = $context->resolveRefAliasName($name);
                if (isset($context->listUnpackAssignSlots[$resolved])) {
                    $slot = $context->listUnpackAssignSlots[$resolved];
                    if (
                        Variable::TYPE_VALUE === $slot->type
                        && Variable::KIND_VARIABLE === $slot->kind
                    ) {
                        $callerVar = $slot;
                    }
                }
            }
            if (Variable::KIND_VALUE === $callerVar->kind) {
                foreach (self::variablesForScopedNameInCallerScopes($context, $name) as $slot) {
                    if (
                        Variable::TYPE_VALUE === $slot->type
                        && Variable::KIND_VARIABLE === $slot->kind
                    ) {
                        $callerVar = $slot;
                        break;
                    }
                }
            }
        }
        if (
            Variable::TYPE_STRING !== $callerVar->type
            || Variable::KIND_VARIABLE !== $callerVar->kind
        ) {
            if (Variable::TYPE_VALUE !== $callerVar->type) {
                return $callerVar;
            }
            $saved = $context->builder->getInsertBlock();
            $context->builder->positionAtEnd($materializeBb);
            $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
            $stringVar = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VARIABLE,
                $slot
            );
            $stringVar->initialize();
            $srcPtr = self::valueStringSourcePtr($context, $callerVar);
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $srcPtr
            );
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $context->builder->store($owned, $slot);
            $stringVar->addref();
            if (null !== $saved) {
                self::restoreInsertBlock($context, $saved);
            }

            return $stringVar;
        }
        $saved = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($materializeBb);
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $stringVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VARIABLE,
            $slot
        );
        $stringVar->initialize();
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->helper->loadValue($callerVar)
        );
        $context->builder->store($owned, $slot);
        $stringVar->addref();
        if (null !== $saved) {
            self::restoreInsertBlock($context, $saved);
        }

        return $stringVar;
    }

    private static function restoreInsertBlock(Context $context, BasicBlock $block): void
    {
        BasicBlockHelper::restoreInsertBlock($context, $block);
    }

    private static function emitCalleeLocalBinding(
        Context $context,
        JIT $jit,
        Operand $calleeOp,
        Variable $callerVar
    ): void {
        $bb = $context->builder->getInsertBlock();
        if (null === $bb) {
            return;
        }

        $calleeVar = $context->scope->variables->contains($calleeOp)
            ? $context->scope->variables[$calleeOp]
            : $context->getVariableFromOp($calleeOp);

        if (
            Variable::TYPE_STRING === $callerVar->type
            && Variable::TYPE_STRING === $calleeVar->type
            && Variable::KIND_VARIABLE === $calleeVar->kind
        ) {
            $context->builder->store(
                $context->helper->loadValue($callerVar),
                $calleeVar->value
            );
            $calleeVar->addref();
            $calleeVar->includeBinding = true;
            $context->setVariableOp($calleeOp, $calleeVar);

            return;
        }

        $jit->assignOperandForced($calleeOp, $callerVar);
        $context->getVariableFromOp($calleeOp)->includeBinding = true;
    }

    private static function callerVariableForName(
        Context $context,
        Block $callerBlock,
        string $name
    ): ?Variable {
        $scoped = $context->variableForScopedName($name);
        if (
            null !== $scoped
            && (Variable::TYPE_VALUE === $scoped->type || Variable::TYPE_STRING === $scoped->type)
        ) {
            return $scoped;
        }
        $callerOp = self::callerOperandByName($callerBlock, $name);
        if (null !== $callerOp && $context->hasVariableOpInScopes($callerOp)) {
            $var = $context->getVariableFromOpInScopes($callerOp);
            if (Variable::TYPE_VALUE === $var->type || Variable::TYPE_STRING === $var->type) {
                return $var;
            }
        }
        $lastAssign = self::lastAssignVariableForName($context, $callerBlock, $name);
        if (null !== $lastAssign) {
            return $lastAssign;
        }

        return self::variableFromCallerAssignConstant($context, $callerBlock, $name);
    }

    private static function stableCallerValueSlot(
        Context $context,
        Block $callerBlock,
        string $name
    ): ?Variable {
        $resolved = $context->resolveRefAliasName($name);
        foreach ($callerBlock->orig->hoistedOperands as $operand) {
            $opName = OperandName::resolve($operand);
            if (null === $opName || $resolved !== $context->resolveRefAliasName($opName)) {
                continue;
            }
            if (!$context->hasVariableOpInScopes($operand)) {
                continue;
            }
            $var = $context->getVariableFromOpInScopes($operand);
            if (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            ) {
                return $var;
            }
        }
        foreach ($callerBlock->scopedOperands() as $operand) {
            $opName = OperandName::resolve($operand);
            if (null === $opName || $resolved !== $context->resolveRefAliasName($opName)) {
                continue;
            }
            if (!$context->hasVariableOpInScopes($operand)) {
                continue;
            }
            $var = $context->getVariableFromOpInScopes($operand);
            if (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            ) {
                return $var;
            }
        }

        return null;
    }

    /**
     * Prefer stable caller slots over ephemeral rvalues at include_entry (#846).
     */
    private static function resolveIncludeCallerVar(
        Context $context,
        ?string $name,
        Variable $callerVar,
        Block $callerBlock
    ): Variable {
        if (null === $name || '' === $name) {
            return $callerVar;
        }
        $candidates = [$callerVar];
        $slotBlock = $context->listUnpackAssignRootBlock ?? $callerBlock;
        $stable = self::stableCallerValueSlot($context, $slotBlock, $name);
        if (null !== $stable) {
            $candidates[] = $stable;
        }
        foreach (self::variablesForScopedNameInCallerScopes($context, $name) as $scopedVar) {
            $candidates[] = $scopedVar;
        }
        $resolved = $context->resolveRefAliasName($name);
        if (isset($context->namedVariableBindings[$resolved])) {
            $candidates[] = $context->namedVariableBindings[$resolved];
        }
        if (isset($context->listUnpackAssignSlots[$resolved])) {
            $candidates[] = $context->listUnpackAssignSlots[$resolved];
        }
        $scoped = $context->variableForScopedName($name);
        if (null !== $scoped) {
            $candidates[] = $scoped;
        }
        $lastAssign = self::lastAssignVariableForName($context, $callerBlock, $name);
        if (null !== $lastAssign) {
            $candidates[] = $lastAssign;
        }
        if (null !== $context->listUnpackAssignRootBlock) {
            $rootAssign = self::lastAssignVariableForName(
                $context,
                $context->listUnpackAssignRootBlock,
                $name
            );
            if (null !== $rootAssign) {
                $candidates[] = $rootAssign;
            }
        }
        $best = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $score = self::includeCallerBindingScore($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return null !== $best && $bestScore > 0 ? $best : $callerVar;
    }

    private static function includeCallerBindingScore(Variable $candidate): int
    {
        if (Variable::TYPE_VALUE === $candidate->type) {
            if (Variable::KIND_VARIABLE === $candidate->kind) {
                return 4;
            }
            if (Variable::KIND_VALUE === $candidate->kind) {
                return 1;
            }
        }
        if (
            Variable::TYPE_STRING === $candidate->type
            && Variable::KIND_VARIABLE === $candidate->kind
        ) {
            return 2;
        }

        return 0;
    }

    /**
     * @return list<Variable>
     */
    private static function variablesForScopedNameInCallerScopes(Context $context, string $name): array
    {
        $vars = [];
        foreach ($context->scope->variables as $op) {
            if (OperandName::resolve($op) === $name) {
                $vars[] = $context->scope->variables[$op];
            }
        }
        for ($i = \count($context->scopeStack) - 1; $i >= 0; --$i) {
            foreach ($context->scopeStack[$i]->variables as $op) {
                if (OperandName::resolve($op) === $name) {
                    $vars[] = $context->scopeStack[$i]->variables[$op];
                }
            }
        }

        return $vars;
    }

    private static function lastAssignVariableForName(
        Context $context,
        Block $block,
        string $name
    ): ?Variable {
        $visited = [];

        return self::lastAssignVariableForNameInBlock($context, $block, $name, $visited);
    }

    /**
     * @param array<int, true> $visited
     */
    private static function lastAssignVariableForNameInBlock(
        Context $context,
        Block $block,
        string $name,
        array &$visited
    ): ?Variable {
        $blockId = \spl_object_id($block);
        if (isset($visited[$blockId])) {
            return null;
        }
        $visited[$blockId] = true;
        $lastAssign = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type) {
                if (null !== $op->arg2) {
                    $alias = $block->getOperand($op->arg2);
                    $aliasName = OperandName::resolve($alias);
                    if (
                        null !== $aliasName
                        && $name === $aliasName
                        && $op->arg1 !== $op->arg2
                    ) {
                        $storage = $block->getOperand($op->arg1);
                        if ($context->hasVariableOpInScopes($storage)) {
                            $storageVar = $context->getVariableFromOpInScopes($storage);
                            if (
                                Variable::KIND_VARIABLE === $storageVar->kind
                                && (Variable::TYPE_VALUE === $storageVar->type || Variable::TYPE_STRING === $storageVar->type)
                            ) {
                                $lastAssign = $storageVar;
                            }
                        }
                    }
                }
                foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                    $dest = $block->getOperand($slotIdx);
                    if (OperandName::resolve($dest) !== $name) {
                        continue;
                    }
                    if ($context->hasVariableOpInScopes($dest)) {
                        $var = $context->getVariableFromOpInScopes($dest);
                        if (
                            Variable::KIND_VARIABLE === $var->kind
                            && (Variable::TYPE_VALUE === $var->type || Variable::TYPE_STRING === $var->type)
                        ) {
                            $lastAssign = $var;
                        }
                    }
                }
            }
            if (null !== $op->block1) {
                $nested = self::lastAssignVariableForNameInBlock($context, $op->block1, $name, $visited);
                if (null !== $nested) {
                    $lastAssign = $nested;
                }
            }
            if (null !== $op->block2) {
                $nested = self::lastAssignVariableForNameInBlock($context, $op->block2, $name, $visited);
                if (null !== $nested) {
                    $lastAssign = $nested;
                }
            }
        }

        return $lastAssign;
    }

    private static function variableFromCallerAssignConstant(
        Context $context,
        Block $callerBlock,
        string $name
    ): ?Variable {
        foreach ($callerBlock->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            $matches = false;
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $dest = $callerBlock->getOperand($slotIdx);
                if (OperandName::resolve($dest) === $name) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches || !isset($callerBlock->constants[$op->arg3])) {
                continue;
            }
            $constant = $callerBlock->constants[$op->arg3];
            if (!$constant instanceof VmVariable || VmVariable::TYPE_STRING !== $constant->type) {
                continue;
            }
            $lit = new Literal($constant->toString());
            $lit->type = Type::string();

            return Variable::fromLiteral($context, $lit);
        }

        return null;
    }

    private static function callerOperandByName(Block $block, string $name): ?Operand
    {
        foreach ($block->scopedOperands() as $operand) {
            if (OperandName::resolve($operand) === $name) {
                return $operand;
            }
        }
        foreach ($block->orig->hoistedOperands as $operand) {
            if (OperandName::resolve($operand) === $name) {
                return $operand;
            }
        }

        return null;
    }

    private static function callerDeclaresLocalName(Block $callerBlock, string $name): bool
    {
        return null !== self::callerOperandByName($callerBlock, $name);
    }

    /**
     * Prefer JIT scope live values at the include site (renderHello $_REQUEST, #784).
     *
     * @param \SplObjectStorage<Operand, Variable> $localBindings
     */
    private static function syncLocalBindingsFromScope(
        Context $context,
        \SplObjectStorage $localBindings,
        Block $callerBlock
    ): void {
        foreach ($localBindings as $operand) {
            $name = OperandName::resolve($operand);
            if (null === $name) {
                continue;
            }
            $live = $context->variableForScopedName($name);
            if (
                null !== $live
                && (Variable::TYPE_VALUE === $live->type || Variable::TYPE_STRING === $live->type)
            ) {
                $localBindings[$operand] = self::resolveIncludeCallerVar(
                    $context,
                    $name,
                    $live,
                    $callerBlock
                );
            }
        }
    }

    private static function hasMultipleAssignsInCaller(Block $callerBlock, string $name): bool
    {
        $count = 0;
        $visited = [];
        self::countAssignsToName($callerBlock, $name, $count, $visited);

        return $count > 1;
    }

    /**
     * @param array<int, true> $visited
     */
    private static function countAssignsToName(Block $block, string $name, int &$count, array &$visited): void
    {
        $blockId = \spl_object_id($block);
        if (isset($visited[$blockId])) {
            return;
        }
        $visited[$blockId] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type) {
                foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                    $dest = $block->getOperand($slotIdx);
                    if (OperandName::resolve($dest) === $name) {
                        ++$count;
                        if ($count > 1) {
                            return;
                        }
                    }
                }
            }
            if (null !== $op->block1) {
                self::countAssignsToName($op->block1, $name, $count, $visited);
                if ($count > 1) {
                    return;
                }
            }
            if (null !== $op->block2) {
                self::countAssignsToName($op->block2, $name, $count, $visited);
                if ($count > 1) {
                    return;
                }
            }
        }
    }

    private static function appendIncludeResume(Context $context, Function_ $func): BasicBlock
    {
        return $func->appendBasicBlock('include_resume_'.(++self::$includeEntrySerial));
    }

    /**
     * Re-store caller locals materialized before the include entry (#784, #866).
     *
     * ?? on $_SERVER/$_GET inside an included template can disturb boxed callee slots;
     * refresh from the pre-include string copies before running post-coalesce code.
     */
    public static function refreshInlineIncludeBindings(Context $context): void
    {
        if ([] === $context->inlineIncludeBindingRefreshStack) {
            return;
        }
        $frameIndex = \count($context->inlineIncludeBindingRefreshStack) - 1;
        $frame = $context->inlineIncludeBindingRefreshStack[$frameIndex];
        if ([] === $frame) {
            return;
        }
        $bb = $context->builder->getInsertBlock();
        if (null === $bb) {
            return;
        }
        if (null !== $bb->getTerminator()) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'include_refresh_cont');
        }
        foreach ($frame as $entry) {
            [$calleeOp, $prepared, $calleeVar, $compileTimeString] = array_pad($entry, 4, null);
            if (Variable::KIND_VARIABLE !== $calleeVar->kind) {
                continue;
            }
            if (null !== $compileTimeString && '' !== $compileTimeString) {
                $restored = $context->builder->load(
                    $context->constantStringFromString($compileTimeString)
                );
            } elseif (Variable::TYPE_STRING === $prepared->type) {
                $restored = $context->helper->loadValue($prepared);
            } else {
                continue;
            }
            self::storeIncludeBindingRestore($context, $calleeOp, $calleeVar, $restored);
            $bindingName = OperandName::resolve($calleeOp);
            if (null === $bindingName) {
                continue;
            }
            foreach ($context->scope->variables as $scopeOp) {
                if (OperandName::resolve($scopeOp) !== $bindingName) {
                    continue;
                }
                $scopeVar = $context->scope->variables[$scopeOp];
                if (Variable::KIND_VARIABLE !== $scopeVar->kind) {
                    continue;
                }
                self::storeIncludeBindingRestore($context, $scopeOp, $scopeVar, $restored);
            }
        }
    }

    private static function storeIncludeBindingRestore(
        Context $context,
        Operand $operand,
        Variable $var,
        \PHPLLVM\Value $restored
    ): void {
        if (Variable::TYPE_STRING === $var->type) {
            $context->builder->store($restored, $var->value);
            $var->addref();
            $var->includeBinding = true;
            $context->setVariableOp($operand, $var);

            return;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            $destPtr = JitValueBox::pointer($context, $var->value);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $destPtr,
                $restored
            );
            $var->includeBinding = true;
            $context->setVariableOp($operand, $var);
        }
    }

    private static function resolveLiteralPath(
        Block $block,
        int $pathSlot,
        Operand $pathOperand,
        Context $context
    ): ?string {
        if ($pathOperand instanceof Operand\Literal && is_string($pathOperand->value)) {
            return $pathOperand->value;
        }
        if (isset($block->constants[$pathSlot])) {
            $constant = $block->constants[$pathSlot];
            if ($constant instanceof VmVariable && VmVariable::TYPE_STRING === $constant->type) {
                return $constant->toString();
            }
        }
        if ($context->hasVariableOp($pathOperand)) {
            return $context->getVariableFromOp($pathOperand)->compileTimeString;
        }

        return null;
    }

    /**
     * Skip argv/cli driver includes when bundling bin/vm.php in compiler_lib_spine_smoke (#2134).
     * cli_spine_shim.php (src/cli.php) and src/cli_driver.php provide skip-entry helpers at runtime; this
     * avoids compiling vendor/autoload Expr_Closure during self-host AOT link.
     */
    /** Stub dynamic requires while host-compiling M3 emit sidecars or full lib-spine AOT (#2699, #8559). */
    private static function shouldStubM3SidecarHostNonLiteralInclude(Block $callerBlock): bool
    {
        $caller = str_replace('\\', '/', $callerBlock->scriptPath());
        $isSpineSmokeEntry = str_ends_with($caller, '/test/selfhost/compiler_lib_spine_smoke/main.php');
        $isSpineSmokeTree = str_contains($caller, '/test/selfhost/compiler_lib_spine_smoke/');

        $sidecarHost = getenv('PHP_COMPILER_M3_SIDECAR_HOST');
        if ('1' === $sidecarHost || 'true' === strtolower((string) $sidecarHost)) {
            return str_ends_with($caller, '/bin/vm.php')
                || str_ends_with($caller, '/src/cli_driver.php')
                || $isSpineSmokeEntry;
        }

        $libSpineBundle = getenv('PHP_COMPILER_LIB_SPINE_BUNDLE');
        if ('1' === $libSpineBundle || 'true' === strtolower((string) $libSpineBundle)) {
            return true;
        }

        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');
        if ('1' === $selfhost || 'true' === strtolower((string) $selfhost)) {
            return $isSpineSmokeEntry
                || $isSpineSmokeTree
                || str_ends_with($caller, '/bin/vm.php')
                || str_ends_with($caller, '/src/cli_driver.php')
                || str_ends_with($caller, '/src/cli.php');
        }

        return false;
    }

    private static function shouldSkipSelfHostSpineCliInclude(string $path): bool
    {
        // This helper exists primarily to keep argv-driver and vendor autoload out of
        // spine smoke bundles. Historically it was gated on SELFHOST_AOT, but bootstrap
        // probes also bundle CLI entrypoints under other flags (issue #1492, #1467).
        $selfhost = getenv('PHP_COMPILER_SELFHOST_AOT');
        $cliSpine = getenv('PHP_COMPILER_CLI_SPINE_BUNDLE');
        $vmSpine = getenv('PHP_COMPILER_VM_SPINE_SMOKE');
        if (
            ('1' !== $selfhost && 'true' !== strtolower((string) $selfhost))
            && ('1' !== $cliSpine && 'true' !== strtolower((string) $cliSpine))
            && ('1' !== $vmSpine && 'true' !== strtolower((string) $vmSpine))
        ) {
            return false;
        }
        $normalized = str_replace('\\', '/', $path);

        return $normalized === 'src/cli.php'
            || $normalized === 'src/cli_driver.php'
            || $normalized === 'vendor/autoload.php'
            || str_ends_with($normalized, '/src/cli.php')
            || str_ends_with($normalized, '/src/cli_driver.php')
            || str_ends_with($normalized, '/vendor/autoload.php');
    }

    private static function emitSkippedSelfHostSpineCliInclude(
        JIT $jit,
        Block $callerBlock,
        ?Operand $resultOperand
    ): void {
        if (null === $resultOperand) {
            return;
        }
        $jit->assignOperand(
            $resultOperand,
            new Variable(
                $jit->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $jit->context->constantFromInteger(1)
            ),
            true
        );
    }
}
