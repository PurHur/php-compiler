<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Type;
use PHPLLVM\BasicBlock;
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
        if (null === $path && [] !== $callerBlock->literalIncludePaths) {
            $path = $callerBlock->literalIncludePaths[array_key_first($callerBlock->literalIncludePaths)];
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
        $entryBb = $func->appendBasicBlock('include_entry_'.(++self::$includeEntrySerial));
        if (null !== $preIncludeBb && null === $preIncludeBb->getTerminator()) {
            $context->builder->positionAtEnd($preIncludeBb);
            $context->builder->branch($entryBb);
        }
        $context->builder->positionAtEnd($entryBb);

        $context->pushScope();
        ++$context->inlineIncludeDepth;
        foreach ($localBindings as $operand) {
            self::emitCalleeLocalBinding(
                $context,
                $jit,
                $func,
                $callerBlock,
                $included,
                $operand,
                $localBindings[$operand]
            );
        }
        try {
            $exitBb = $jit->compileIncludedAtEntry($func, $included, $entryBb);
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

    private static function emitCalleeLocalBinding(
        Context $context,
        JIT $jit,
        Function_ $func,
        Block $callerBlock,
        Block $included,
        Operand $calleeOp,
        Variable $callerVar
    ): void {
        $bb = $context->builder->getInsertBlock();
        if (null === $bb) {
            return;
        }
        if (!$context->hasVariableOp($calleeOp)) {
            $context->makeVariableFromOp($func, $bb, $included, $calleeOp);
        }
        $calleeVar = $context->getVariableFromOp($calleeOp);
        $name = OperandName::resolve($calleeOp);
        if (null !== $name) {
            foreach ($callerBlock->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN !== $op->type) {
                    continue;
                }
                $matches = false;
                foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                    if (OperandName::resolve($callerBlock->getOperand($slotIdx)) === $name) {
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
                $native = $context->builder->load(
                    $context->constantStringFromString($constant->toString())
                );
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $native
                );
                $context->builder->store($owned, $calleeVar->value);
                $calleeVar->addref();
                $context->setVariableOp($calleeOp, $calleeVar);

                return;
            }
        }
        if (
            Variable::TYPE_STRING === $callerVar->type
            && Variable::KIND_VARIABLE === $callerVar->kind
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
        $callerOp = self::callerOperandByName($callerBlock, $name);
        if (null !== $callerOp && $context->hasVariableOp($callerOp)) {
            return $context->getVariableFromOp($callerOp);
        }
        foreach ($callerBlock->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $dest = $callerBlock->getOperand($slotIdx);
                if (OperandName::resolve($dest) !== $name) {
                    continue;
                }
                if ($context->hasVariableOp($dest)) {
                    return $context->getVariableFromOp($dest);
                }
            }
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
