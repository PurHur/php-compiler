<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Operand;
use PHPLLVM\Value\Function_;

/**
 * JIT/AOT: echo/print `$this` outside object context (zend_execute.c ZEND_ECHO / FETCH_THIS, #31901).
 *
 * Proven at lowering time when the operand is `$this` and the current func is FLAG_STATIC,
 * `{main}`, or a plain function — same Error as VM {@see \PHPCompiler\VM::guardUnboundThisRead()}.
 */
final class UnboundThisGuard
{
    public const ERROR_MESSAGE = 'Using $this when not in object context';

    /**
     * Emit catchable Error (or pend+return for the caller) when `$this` is proven unbound.
     *
     * @return true when the echo/print must be skipped
     */
    public static function emitIfProven(
        Context $context,
        \PHPCompiler\JIT $jit,
        Block $block,
        ?Operand $op
    ): bool {
        if (!self::isProvenUnboundThis($block, $op)) {
            return false;
        }

        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ErrorRaise::ensureStandaloneBodies($context);
        }

        $fn = $context->builder->getInsertBlock()?->getParent();
        if (!$fn instanceof Function_) {
            self::emitThrow($context, $jit, null);

            return true;
        }
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            self::emitThrow($context, $jit, $fn);

            return true;
        }

        $failBlock = $fn->appendBasicBlock('unbound_this_echo_error');
        $continueBlock = $fn->appendBasicBlock('unbound_this_echo_dead');
        $context->builder->positionAtEnd($entry);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitThrow($context, $jit, $fn);

        $context->builder->positionAtEnd($continueBlock);

        return true;
    }

    public static function isProvenUnboundThis(Block $block, ?Operand $op): bool
    {
        if (null === $op) {
            return false;
        }
        if (!self::operandIsThis($block, $op)) {
            return false;
        }
        $func = $block->func;
        if (null === $func) {
            return true;
        }
        $flags = (int) ($func->flags ?? 0);
        if (($flags & CfgFunc::FLAG_STATIC) !== 0) {
            return true;
        }
        // Non-static closures may be bound at runtime — do not specialize.
        if (($flags & CfgFunc::FLAG_CLOSURE) !== 0) {
            return false;
        }
        // {main} / plain function — FETCH_THIS is never in object context.
        if (null === $func->class) {
            return true;
        }

        return false;
    }

    private static function operandIsThis(Block $block, Operand $op): bool
    {
        if ('this' === OperandName::resolve($op)) {
            return true;
        }
        $thisSlot = $block->slotIndexForVariableName('this');
        $opSlot = $block->slotForOperand($op);

        return null !== $thisSlot && null !== $opSlot && $thisSlot === $opSlot;
    }

    private static function emitThrow(Context $context, \PHPCompiler\JIT $jit, ?Function_ $fn): void
    {
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, self::ERROR_MESSAGE);
            $insert = BasicBlockHelper::tryGetInsertBlock($context);
            if (null !== $insert && null === $insert->getTerminator() && null !== $fn) {
                self::pendAndReturn($context, $fn);
            }

            return;
        }
        if (null !== $fn) {
            self::pendAndReturn($context, $fn);

            return;
        }
        ErrorRaise::emitRaise($context, self::ERROR_MESSAGE);
    }

    private static function pendAndReturn(Context $context, Function_ $fn): void
    {
        TryCatchHelper::emitPendErrorForCaller($context, self::ERROR_MESSAGE);
        TryCatchHelper::emitPropagateReturnAfterPendingThrow($context, $fn);
    }
}
