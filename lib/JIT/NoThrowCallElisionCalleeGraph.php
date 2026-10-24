<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * CFG / callee-graph proofs for no-throw user-function elision (#36387).
 *
 * Extracted from {@see NoThrowCallElision} so the hub is not one monolith TU
 * on the gen-0 spine (split-TU / size-budget ratchet). Public analyze/refine
 * entry points and pure-builtin arg dispatcher stay on the hub; this trait
 * holds {@code bodyIsNoThrowCalleeGraph} and method/static/literal helpers.
 *
 * Used via {@code use NoThrowCallElisionCalleeGraph;} on
 * {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: Zend/{zend_execute,zend_execute_API,zend_exceptions}.c
 * (EG execute_data / uncaught frame model that AOT elides when the callee CFG
 * cannot throw).
 */
trait NoThrowCallElisionCalleeGraph
{
    /**
     * True when the body cannot throw and every FUNCCALL target is self or an
     * already-proven no-throw user function.
     */
    private static function bodyIsNoThrowCalleeGraph(
        Block $entry,
        string $selfLc,
        Context $context
    ): bool {
        $seen = [];
        $stack = [$entry];
        while ([] !== $stack) {
            /** @var Block $block */
            $block = array_pop($stack);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            foreach ($block->opCodes as $op) {
                $type = $op->type;
                if (OpCode::TYPE_FUNCDEF === $type || OpCode::TYPE_CLOSURE === $type) {
                    // Nested declarations are other functions — do not attribute their
                    // bodies to this one, and do not walk into them.
                    continue;
                }
                if (OpCode::TYPE_THROW === $type
                    || OpCode::TYPE_NEW === $type
                    || OpCode::TYPE_INCLUDE === $type
                    || OpCode::TYPE_FROM_CALLABLE === $type
                ) {
                    return false;
                }
                if (self::opMayRaiseEngineError($block, $op)) {
                    return false;
                }
                if (OpCode::TYPE_FUNCCALL_INIT === $type) {
                    if (!empty($op->funcCallDynamic)) {
                        return false;
                    }
                    $nameOp = $block->getOperand($op->arg1);
                    if (!$nameOp instanceof Operand\Literal) {
                        return false;
                    }
                    $calleeLc = strtolower((string) $nameOp->value);
                    if (!self::isAllowedNoThrowCallee($context, $selfLc, $calleeLc)) {
                        return false;
                    }
                }
                if (OpCode::TYPE_METHODCALL_INIT === $type) {
                    // Same-class `$this->leaf()` chains: allow when the target method
                    // is already proven no-throw (fixpoint upgrades mid after leaf).
                    // Cross-class bare-name matches are rejected — two classes can
                    // share a method name with different throw behaviour (#36386).
                    $methodLc = self::literalMethodNameLc($block, $op->arg2);
                    if (null === $methodLc
                        || !self::isAllowedNoThrowMethodCallee($context, $selfLc, $methodLc)
                    ) {
                        return false;
                    }
                }
                if (OpCode::TYPE_STATICCALL_INIT === $type) {
                    // Same-class `self::leaf()` / `A::leaf()` chains — same fixpoint
                    // as METHODCALL. `parent::` stays conservative (needs inheritance).
                    $classLc = self::literalClassNameLc($block, $op->arg1);
                    $methodLc = self::literalMethodNameLc($block, $op->arg2);
                    if (null === $classLc
                        || null === $methodLc
                        || !self::isAllowedNoThrowStaticCallee($context, $selfLc, $classLc, $methodLc)
                    ) {
                        return false;
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $child) {
                    if ($child instanceof Block) {
                        $stack[] = $child;
                    }
                }
            }
            foreach ($block->blocks as $child) {
                if ($child instanceof Block) {
                    $stack[] = $child;
                }
            }
        }

        return true;
    }

    private static function isAllowedNoThrowCallee(
        Context $context,
        string $selfLc,
        string $calleeLc
    ): bool {
        // Self-recursion uses the bare method name in CFG; scoped
        // `Class::method` keys must still match (#36386 leaf methods).
        if ($calleeLc === $selfLc || $calleeLc === self::bareName($selfLc)) {
            return true;
        }
        if (!empty($context->noThrowUserFunctions[$calleeLc])) {
            return true;
        }
        // Scoped key vs bare CFG name (Class::leaf ↔ leaf).
        $bare = self::bareName($calleeLc);
        if ($bare !== $calleeLc && !empty($context->noThrowUserFunctions[$bare])) {
            return true;
        }
        foreach ($context->noThrowUserFunctions as $knownLc => $ok) {
            if (!$ok) {
                continue;
            }
            if (self::bareName($knownLc) === $calleeLc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Instance method callees are keyed {@code class::method}. Prefer the
     * caller's class scope so {@code B::leaf} throwing does not unlock
     * {@code A::mid}'s {@code $this->leaf()} when only {@code A::leaf} is safe.
     */
    private static function isAllowedNoThrowMethodCallee(
        Context $context,
        string $selfLc,
        string $methodLc
    ): bool {
        if ($methodLc === self::bareName($selfLc)) {
            return true;
        }
        $class = self::classPrefix($selfLc);
        if ('' !== $class) {
            $scoped = $class.'::'.$methodLc;
            if (!empty($context->noThrowUserFunctions[$scoped])) {
                return true;
            }
        }
        if (!empty($context->noThrowUserFunctions[$methodLc])) {
            return true;
        }

        return false;
    }

    /**
     * Resolve {@code self::}/{@code static::}/{@code Class::} static callees.
     * Prefer the explicit class::method key so {@code B::leaf} throwing does not
     * unlock {@code A::mid}'s {@code self::leaf()} when only {@code A::leaf} is safe.
     */
    private static function isAllowedNoThrowStaticCallee(
        Context $context,
        string $selfLc,
        string $classLitLc,
        string $methodLc
    ): bool {
        if ('parent' === $classLitLc) {
            return false;
        }
        $callerClass = self::classPrefix($selfLc);
        $targetClass = $classLitLc;
        if ('self' === $targetClass || 'static' === $targetClass) {
            if ('' === $callerClass) {
                return false;
            }
            $targetClass = $callerClass;
        }
        $targetClass = ltrim($targetClass, '\\');
        if ('' === $targetClass || '' === $methodLc) {
            return false;
        }
        // Recursing into the same static method (rare) — bare or scoped.
        if ($methodLc === self::bareName($selfLc)
            && ('' === $callerClass || $targetClass === $callerClass)
        ) {
            return true;
        }
        $scoped = $targetClass.'::'.$methodLc;
        if (!empty($context->noThrowUserFunctions[$scoped])) {
            return true;
        }
        if (!empty($context->noThrowUserFunctions[$methodLc])
            && ('' === $callerClass || $targetClass === $callerClass)
        ) {
            return true;
        }

        return false;
    }

    private static function literalClassNameLc(Block $block, ?int $classSlot): ?string
    {
        return self::literalOperandStringLc($block, $classSlot);
    }

    private static function literalMethodNameLc(Block $block, ?int $nameSlot): ?string
    {
        return self::literalOperandStringLc($block, $nameSlot);
    }

    private static function literalOperandStringLc(Block $block, ?int $slot): ?string
    {
        if (null === $slot) {
            return null;
        }
        $nameOp = $block->getOperand($slot);
        if (!$nameOp instanceof Operand\Literal && isset($block->constants[$slot])) {
            $nameOp = new Operand\Literal($block->constants[$slot]->toString());
        }
        if (!$nameOp instanceof Operand\Literal) {
            return null;
        }
        $raw = is_string($nameOp->value) ? $nameOp->value : (string) $nameOp->value;
        if ('' === $raw) {
            return null;
        }

        return strtolower($raw);
    }

    private static function bareName(string $scopedLc): string
    {
        $pos = strrpos($scopedLc, '::');
        if (false === $pos) {
            return $scopedLc;
        }

        return substr($scopedLc, $pos + 2);
    }

    private static function classPrefix(string $scopedLc): string
    {
        $pos = strrpos($scopedLc, '::');
        if (false === $pos) {
            return '';
        }

        return substr($scopedLc, 0, $pos);
    }


    /**
     * Engine errors (TypeError, DivisionByZeroError, ArithmeticError, Error) raised by the
     * op itself, not by a call. The old analysis only looked for `throw`/`new`/calls, so
     * `function f($a, $b) { return $a + $b; }` was "no-throw" and f(new Foo, 1) / f("x", 1)
     * left its TypeError pending past the caller's next statement.
     *
     * Allow-list: ops listed here are safe (with operand-type conditions); anything else
     * is assumed to possibly raise.
     */
    private static function opMayRaiseEngineError(Block $block, OpCode $op): bool
    {
        switch ($op->type) {
            case OpCode::TYPE_ASSIGN:
            case OpCode::TYPE_JUMP:
            case OpCode::TYPE_JUMPIF:
            case OpCode::TYPE_RETURN:
            case OpCode::TYPE_RETURN_VOID:
            case OpCode::TYPE_ARG_RECV:
            case OpCode::TYPE_ARG_SEND:
            case OpCode::TYPE_FUNCCALL_INIT:
            case OpCode::TYPE_METHODCALL_INIT:
            case OpCode::TYPE_STATICCALL_INIT:
            case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
            case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
            case OpCode::TYPE_FUNCDEF:
            case OpCode::TYPE_CLOSURE:
            case OpCode::TYPE_TYPE_ASSERT:
            case OpCode::TYPE_SMALLER:
            case OpCode::TYPE_SMALLER_OR_EQUAL:
            case OpCode::TYPE_GREATER:
            case OpCode::TYPE_GREATER_OR_EQUAL:
            case OpCode::TYPE_EQUAL:
            case OpCode::TYPE_NOT_EQUAL:
            case OpCode::TYPE_IDENTICAL:
            case OpCode::TYPE_NOT_IDENTICAL:
            case OpCode::TYPE_SPACESHIP:
            case OpCode::TYPE_BOOLEAN_NOT:
            case OpCode::TYPE_LOGICAL_XOR:
            case OpCode::TYPE_ISSET:
            case OpCode::TYPE_EMPTY:
            case OpCode::TYPE_COALESCE:
            case OpCode::TYPE_CAST_BOOL:
            case OpCode::TYPE_INSTANCEOF:
            case OpCode::TYPE_INIT_ARRAY:
            case OpCode::TYPE_TICK_SCOPE_ENTER:
            case OpCode::TYPE_TICK_SCOPE_SET:
            case OpCode::TYPE_TICK_SCOPE_LEAVE:
                return false;

            case OpCode::TYPE_PLUS:
            case OpCode::TYPE_MINUS:
            case OpCode::TYPE_MUL:
            case OpCode::TYPE_POW:
            case OpCode::TYPE_BITWISE_AND:
            case OpCode::TYPE_BITWISE_OR:
            case OpCode::TYPE_BITWISE_XOR:
                return !self::operandIsNumeric($block, $op->arg1) || !self::operandIsNumeric($block, $op->arg2);

            case OpCode::TYPE_UNARY_MINUS:
            case OpCode::TYPE_UNARY_PLUS:
            case OpCode::TYPE_BITWISE_NOT:
            case OpCode::TYPE_PRE_INC:
            case OpCode::TYPE_PRE_DEC:
            case OpCode::TYPE_POST_INC:
            case OpCode::TYPE_POST_DEC:
            case OpCode::TYPE_CAST_INT:
            case OpCode::TYPE_CAST_FLOAT:
                return !self::operandIsNumeric($block, $op->arg1);

            case OpCode::TYPE_CONCAT:
            case OpCode::TYPE_ECHO:
            case OpCode::TYPE_PRINT:
            case OpCode::TYPE_CAST_STRING:
                foreach ([$op->arg1, $op->arg2] as $slot) {
                    if (null !== $slot && !self::operandIsScalar($block, $slot)) {
                        return true;
                    }
                }

                return false;

            case OpCode::TYPE_DIV:
            case OpCode::TYPE_MODULO:
                // DivisionByZeroError for any zero divisor; only a non-zero literal is safe.
                $divisor = self::literalOperandValue($block, $op->arg2);

                return !self::operandIsNumeric($block, $op->arg1)
                    || !(\is_int($divisor) || \is_float($divisor)) || 0 == $divisor;

            case OpCode::TYPE_SHIFT_LEFT:
            case OpCode::TYPE_SHIFT_RIGHT:
                // ArithmeticError for a negative shift count.
                $count = self::literalOperandValue($block, $op->arg2);

                return !self::operandIsNumeric($block, $op->arg1) || !\is_int($count) || $count < 0;

            case OpCode::TYPE_ARRAY_DIM_FETCH:
                return !self::operandHasType($block, $op->arg1, [\PHPTypes\Type::TYPE_ARRAY]);
        }

        return true;
    }

    private static function operandIsNumeric(Block $block, ?int $slot): bool
    {
        return self::operandHasType($block, $slot, [
            \PHPTypes\Type::TYPE_LONG,
            \PHPTypes\Type::TYPE_DOUBLE,
            \PHPTypes\Type::TYPE_BOOLEAN,
            \PHPTypes\Type::TYPE_NULL,
        ]);
    }

    private static function operandIsScalar(Block $block, ?int $slot): bool
    {
        return self::operandHasType($block, $slot, [
            \PHPTypes\Type::TYPE_LONG,
            \PHPTypes\Type::TYPE_DOUBLE,
            \PHPTypes\Type::TYPE_BOOLEAN,
            \PHPTypes\Type::TYPE_NULL,
            \PHPTypes\Type::TYPE_STRING,
        ]);
    }

    /**
     * True when the operand's inferred type is one of $kinds (a union must consist only of
     * them). Unknown/mixed operands are false.
     *
     * @param list<int> $kinds
     */
    private static function operandHasType(Block $block, ?int $slot, array $kinds): bool
    {
        if (null === $slot) {
            return false;
        }
        $operand = $block->getOperand($slot);
        if (null === $operand) {
            return false;
        }
        if ($operand instanceof Operand\Literal) {
            $v = $operand->value;

            return match (true) {
                \is_int($v) => \in_array(\PHPTypes\Type::TYPE_LONG, $kinds, true),
                \is_float($v) => \in_array(\PHPTypes\Type::TYPE_DOUBLE, $kinds, true),
                \is_bool($v) => \in_array(\PHPTypes\Type::TYPE_BOOLEAN, $kinds, true),
                null === $v => \in_array(\PHPTypes\Type::TYPE_NULL, $kinds, true),
                \is_string($v) => \in_array(\PHPTypes\Type::TYPE_STRING, $kinds, true),
                default => false,
            };
        }
        $type = $operand->type ?? null;
        if (!$type instanceof \PHPTypes\Type) {
            return false;
        }
        if (\PHPTypes\Type::TYPE_UNION === $type->type) {
            if ([] === $type->subTypes) {
                return false;
            }
            foreach ($type->subTypes as $sub) {
                if (!\in_array($sub->type, $kinds, true)) {
                    return false;
                }
            }

            return true;
        }

        return \in_array($type->type, $kinds, true);
    }

    private static function literalOperandValue(Block $block, ?int $slot): mixed
    {
        if (null === $slot) {
            return null;
        }
        $operand = $block->getOperand($slot);

        return $operand instanceof Operand\Literal ? $operand->value : null;
    }
}
