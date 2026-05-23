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
            throw new \LogicException(
                'include/require must use a compile-time literal path for JIT/AOT (issue #54)'
            );
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

        $included = $context->runtime->parseAndCompileFile($path);
        if (null === $included) {
            throw new \LogicException('failed to compile include: '.$path);
        }

        $included->inheritScopeFrom($callerBlock);
        $included->inheritUndefinedLocals = true;

        $localBindings = self::collectCalleeLocalBindings($context, $callerBlock, $included);
        $preIncludeBb = $context->builder->getInsertBlock();
        $preparedBindings = new \SplObjectStorage();
        if (null !== $preIncludeBb) {
            $context->builder->positionAtEnd($preIncludeBb);
            foreach ($localBindings as $operand) {
                $preparedBindings[$operand] = self::prepareCallerBinding(
                    $context,
                    $preIncludeBb,
                    $localBindings[$operand]
                );
            }
        }
        $returnHolderOp = new Temporary();
        if (null !== $preIncludeBb) {
            $context->builder->positionAtEnd($preIncludeBb);
        }
        $returnHolder = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            JitValueBox::alloc($context)
        );
        JitValueBox::writeLong($context, $returnHolder->value, $context->constantFromInteger(1));
        $context->setVariableOp($returnHolderOp, $returnHolder);
        $context->inlineIncludeReturnOperands[] = $returnHolderOp;
        $entryBb = $func->appendBasicBlock('include_entry_'.(++self::$includeEntrySerial));
        if (null !== $preIncludeBb && null === $preIncludeBb->getTerminator()) {
            $context->builder->positionAtEnd($preIncludeBb);
            $context->builder->branch($entryBb);
        }
        $context->builder->positionAtEnd($entryBb);

        $context->pushScope();
        ++$context->inlineIncludeDepth;
        $context->builder->positionAtEnd($entryBb);
        foreach ($localBindings as $operand) {
            if (!$context->hasVariableOp($operand)) {
                $calleeVar = new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VARIABLE,
                    $context->builder->alloca($context->getTypeFromString('__string__*'))
                );
                $calleeVar->initialize();
                $context->setVariableOp($operand, $calleeVar);
            }
        }
        $context->builder->positionAtEnd($entryBb);
        foreach ($localBindings as $operand) {
            self::emitCalleeLocalBinding(
                $context,
                $jit,
                $operand,
                $preparedBindings[$operand] ?? $localBindings[$operand]
            );
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
            $exitBb = $jit->compileIncludedAtEntry($func, $included, $bodyBb);
        } finally {
            --$context->inlineIncludeDepth;
            $context->popScope();
        }

        $resumeBb = self::appendIncludeResume($context, $func);
        $context->builder->positionAtEnd($exitBb);
        if (null === $exitBb->getTerminator()) {
            $context->builder->branch($resumeBb);
        }
        $context->builder->positionAtEnd($resumeBb);

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
     * @return \SplObjectStorage<Operand, Variable>
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
            $callerVar = self::callerVariableForName($context, $callerBlock, $name);
            if (null !== $callerVar) {
                $bindings[$operand] = $callerVar;
            }
        }

        return $bindings;
    }

    /**
     * Read boxed caller locals while preIncludeBb still dominates the caller alloca (#784).
     */
    private static function prepareCallerBinding(
        Context $context,
        BasicBlock $materializeBb,
        Variable $callerVar
    ): Variable {
        if (Variable::TYPE_VALUE !== $callerVar->type) {
            return $callerVar;
        }
        $saved = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($materializeBb);
        $slot = $context->builder->alloca($context->getTypeFromString('__string__*'));
        $stringVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VARIABLE,
            $slot
        );
        $stringVar->initialize();
        $srcPtr = JitValueBox::valuePtrFromVariable($context, $callerVar);
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
            $context->builder->positionAtEnd($saved);
        }

        return $stringVar;
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

        $calleeVar = $context->getVariableFromOp($calleeOp);

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
            $context->setVariableOp($calleeOp, $calleeVar);

            return;
        }

        $jit->assignOperandForced($calleeOp, $callerVar);
    }

    private static function callerVariableForName(
        Context $context,
        Block $callerBlock,
        string $name
    ): ?Variable {
        foreach ($callerBlock->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $dest = $callerBlock->getOperand($slotIdx);
                if (OperandName::resolve($dest) !== $name) {
                    continue;
                }
                if ($context->hasVariableOpInScopes($dest)) {
                    $var = $context->getVariableFromOpInScopes($dest);
                    if (Variable::TYPE_VALUE === $var->type || Variable::TYPE_STRING === $var->type) {
                        return $var;
                    }
                }
            }
        }
        $callerOp = self::callerOperandByName($callerBlock, $name);
        if (null !== $callerOp && $context->hasVariableOpInScopes($callerOp)) {
            $var = $context->getVariableFromOpInScopes($callerOp);
            if (Variable::TYPE_VALUE === $var->type || Variable::TYPE_STRING === $var->type) {
                return $var;
            }
        }
        $scoped = $context->variableForScopedName($name);
        if (
            null !== $scoped
            && (Variable::TYPE_VALUE === $scoped->type || Variable::TYPE_STRING === $scoped->type)
        ) {
            return $scoped;
        }

        return self::variableFromCallerAssignConstant($context, $callerBlock, $name);
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

    private static function appendIncludeResume(Context $context, Function_ $func): BasicBlock
    {
        return $func->appendBasicBlock('include_resume_'.(++self::$includeEntrySerial));
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
}
