<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPLLVM;

/**
 * Script magic / include / clone opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_SCRIPT_MAGIC},
 * {@code TYPE_INCLUDE}, {@code TYPE_CLONE}. Move-only; no IR shape change.
 *
 * php-src: Zend/zend_compile.h (__FILE__/__DIR__/__COMPILER_HALT_OFFSET__),
 * Zend/zend_vm_def.h (ZEND_INCLUDE_OR_EVAL, ZEND_CLONE), Zend/zend_execute_API.c
 * (zend_include_or_eval, zend_objects_clone_obj) — move-only Concern extract;
 * no new C ABI.
 */
trait CompileScriptMagicIncludeClone
{
    private function compileScriptMagicIncludeCloneOp(
        Block $block,
        OpCode $op,
        PHPLLVM\Value $func
    ): void {
        switch ($op->type) {
            case OpCode::TYPE_SCRIPT_MAGIC:
                if (OpCode::SCRIPT_MAGIC_HALT_OFFSET === (int) $op->arg3) {
                    $offset = $block->haltCompilerOffset;
                    if (null === $offset) {
                        throw new \LogicException('Undefined constant "__COMPILER_HALT_OFFSET__"');
                    }
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        \PHPCompiler\JIT\Variable::fromConstantInt($this->context, $offset)
                    );
                } elseif (OpCode::SCRIPT_MAGIC_LINE === (int) $op->arg3) {
                    $line = null !== $op->arg2 ? (int) $op->arg2 : 1;
                    if ($line < 1) {
                        $line = 1;
                    }
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        \PHPCompiler\JIT\Variable::fromConstantInt($this->context, $line)
                    );
                } else {
                    $magicStr = \PHPCompiler\JIT\ScriptMagic::stringForBlock($block, (int) $op->arg3);
                    $lit = new Operand\Literal($magicStr);
                    $lit->type = \PHPTypes\Type::string();
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        \PHPCompiler\JIT\Variable::fromLiteral($this->context, $lit)
                    );
                }
                break;
            case OpCode::TYPE_INCLUDE:
                if ($this->context->inlineIncludeDepth > 0) {
                    \PHPCompiler\JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                }
                \PHPCompiler\JIT\IncludeHelper::compileLiteral(
                    $this,
                    $func,
                    $block,
                    $op,
                    null !== $op->arg2 ? $block->getOperand($op->arg2) : null
                );
                break;
            case OpCode::TYPE_CLONE:
                \PHPCompiler\JIT\CloneOperandHelper::compile($this, $this->context, $block, $op);
                break;
            default:
                throw new \LogicException('compileScriptMagicIncludeCloneOp: unexpected opcode '.$op->type);
        }
    }
}
