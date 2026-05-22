<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
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
        $context->pushScope();
        foreach ($localBindings as $operand) {
            $context->setVariableOp($operand, $localBindings[$operand]);
        }
        try {
            $exitBb = $jit->compileSubBlock($func, $included);
        } finally {
            $context->popScope();
        }
        $context->builder->positionAtEnd($exitBb);
        if (null === $exitBb->getTerminator()) {
            $context->builder->returnVoid();
        }

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
     */
    /**
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
            foreach ($context->scope->variables as $callerOp) {
                if (OperandName::resolve($callerOp) !== $name) {
                    continue;
                }
                $bindings[$operand] = $context->scope->variables[$callerOp];

                break;
            }
        }

        return $bindings;
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
