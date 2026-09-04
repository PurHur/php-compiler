<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPTypes\Type;

/**
 * Outermost nested Array_ call-arg matching + by-ref / named-arg helpers (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers matchOutermostNestedInlineArrayProducerForCallArg, BuiltinByRefParams
 * wiring (array_multisort SORT_* carve-out), and named-parameter / unpack /
 * IncDec helpers used from compileCallArgSends / InlineCallArgProducerMatch.
 *
 * php-src: ext/standard/array.c (`PHP_FUNCTION(array_multisort)` by-ref args).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait NestedArrayOutermostAndByRefCallArgHelpers
{
    /**
     * Outermost inline Array_ producer for nested literal call args (descriptor_spec, etc.) (#11485).
     *
     * @param list<Op\Expr> $producers
     * @param list<Op\Expr\Array_> $arrayProducers
     */
    private function matchOutermostNestedInlineArrayProducerForCallArg(
        array $producers,
        array $arrayProducers,
        int $argIndex,
        int $argCount
    ): ?Op\Expr\Array_ {
        if ([] === $arrayProducers) {
            return null;
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null !== $nestedTrailing) {
            [$arrayChain, $trailing] = $nestedTrailing;
            if (1 + \count($trailing) === $argCount && $argIndex < \count($arrayChain)) {
                $outer = $arrayChain[\count($arrayChain) - 1] ?? null;

                return $outer instanceof Op\Expr\Array_ ? $outer : null;
            }
        }
        if (
            \count($arrayProducers) >= 2
            && $this->producersAreNestedArrayLiteralChain($arrayProducers)
            && $this->arrayProducersFormNestedChain($arrayProducers)
        ) {
            $outer = $arrayProducers[\count($arrayProducers) - 1];

            return $outer instanceof Op\Expr\Array_ ? $outer : null;
        }

        return $arrayProducers[\count($arrayProducers) - 1];
    }

    /**
     * By-ref named locals are real CV operands, not hoisted inline FuncCall producers (#15476, #13714).
     */
    private function isByRefNamedCallArgExcludedFromSiblingProducerWiring(
        Op $consumer,
        int $argIndex,
        Operand $arg
    ): bool {
        if (!$this->isNamedVariableOperand($arg)) {
            return false;
        }
        if (!$consumer instanceof Op\Expr\FuncCall && !$consumer instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($consumer);
        if (null === $calleeName) {
            return false;
        }

        return $this->callArgRequiresByRef($calleeName, $argIndex, $arg);
    }

    private function callArgRequiresByRef(string $calleeName, int $argIndex, ?Operand $arg = null, ?Block $block = null): bool
    {
        if ('array_multisort' === strtolower($calleeName)) {
            if (null !== $arg && null !== $block && $this->isArrayMultisortSortFlagOperand($arg, $block)) {
                return false;
            }

            return true;
        }
        $lc = strtolower($calleeName);
        if (isset($this->userFunctionParamByRef[$lc][$argIndex])) {
            return true;
        }
        if (\in_array($argIndex, BuiltinByRefParams::forFunction($calleeName), true)) {
            return true;
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($calleeName);

        return null !== $variadicFrom && $argIndex >= $variadicFrom;
    }

    /**
     * array_multisort() SORT_* / Sorting enum operands are by-value (#9481, ext/standard/array.c).
     */
    private function isArrayMultisortSortFlagOperand(Operand $arg, Block $block): bool
    {
        if ($this->operandLooksLikeArrayMultisortSortFlag($arg)) {
            return true;
        }
        $slot = $this->tryFoldCallArgCompileTimeValue($arg, $block, 'array_multisort', null);
        if (null === $slot || !isset($block->constants[$slot])) {
            return false;
        }
        $const = $block->constants[$slot];
        if (Variable::TYPE_INTEGER !== $const->type) {
            return false;
        }
        $val = $const->toInt();
        $masked = $val & ~\PHPCompiler\ext\standard\StdlibConstants::SORT_FLAG_CASE;

        return \in_array($masked, [
            \PHPCompiler\ext\standard\StdlibConstants::SORT_ASC,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_DESC,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_REGULAR,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_NUMERIC,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_STRING,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_NATURAL,
            \PHPCompiler\ext\standard\StdlibConstants::SORT_LOCALE_STRING,
        ], true) || 0 !== ($val & \PHPCompiler\ext\standard\StdlibConstants::SORT_FLAG_CASE);
    }

    /** SORT_* / Sorting enum operands in array_multisort() are by-value (#9481). */
    private function operandLooksLikeArrayMultisortSortFlag(Operand $arg): bool
    {
        if ($arg instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($arg->name);
            if (null !== $name && str_starts_with(strtoupper($name), 'SORT_')) {
                return true;
            }
        }
        if ($arg instanceof Op\Expr\ClassConstFetch) {
            $class = $this->staticNameFromOperand($arg->class);
            if (null !== $class && 0 === strcasecmp(ltrim($class, '\\'), 'Sorting')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a compile-time global function name from a php-cfg FuncCall/NsFuncCall expr.
     */
    private function funcCallExprCalleeName(Op\Expr $call): ?string
    {
        if ($call instanceof Op\Expr\FuncCall || $call instanceof Op\Expr\NsFuncCall) {
            return $this->staticNameFromOperand($call->name);
        }

        return null;
    }

    /**
     * Statement-level VM builtins that take by-ref args must compile eagerly — deferring as
     * sibling inline producers drops mutations (natcasesort + array_values + implode, #12732).
     */
    private function funcCallExprHasByRefMutatingSideEffects(Op $op): bool
    {
        if (!$op instanceof Op\Expr\FuncCall && !$op instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($op);
        if (null === $calleeName) {
            return false;
        }
        if ([] !== BuiltinByRefParams::forFunction($calleeName)) {
            return true;
        }

        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($calleeName);
        if (null !== $variadicFrom) {
            if (
                property_exists($op, 'args')
                && \is_array($op->args)
                && \count($op->args) <= $variadicFrom
            ) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * True when $arg is passed by reference to a VM builtin in $call (issue #9074).
     */
    private function funcCallExprByRefArgMatchesOperand(Op\Expr $call, Operand $arg): bool
    {
        if (
            !($call instanceof Op\Expr\FuncCall || $call instanceof Op\Expr\NsFuncCall)
            || !property_exists($call, 'args')
            || !is_array($call->args)
        ) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($call);
        if (null === $calleeName) {
            return false;
        }
        foreach (BuiltinByRefParams::forFunction($calleeName) as $idx) {
            if (!isset($call->args[$idx])) {
                continue;
            }
            if ($this->operandsReferToSameVariable($call->args[$idx], $arg)) {
                return true;
            }
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($calleeName);
        if (null === $variadicFrom) {
            return false;
        }
        $n = \count($call->args);
        for ($i = $variadicFrom; $i < $n; ++$i) {
            if (!isset($call->args[$i])) {
                continue;
            }
            if (
                'array_multisort' === strtolower($calleeName)
                && $this->operandLooksLikeArrayMultisortSortFlag($call->args[$i])
            ) {
                continue;
            }
            if ($this->operandsReferToSameVariable($call->args[$i], $arg)) {
                return true;
            }
        }

        return false;
    }

    private function callArgUnpack(Operand $arg): bool
    {
        return property_exists($arg, 'callArgUnpack') && true === $arg->callArgUnpack;
    }

    /**
     * Zend zend_compile.c: positional-after-named, unpack ordering (#4299, #4663).
     * Duplicate named params are deferred to runtime (zend_execute.c, #16652).
     *
     * @param list<Operand> $args
     */
    private function validateCallArgOrder(array $args): void
    {
        $hadNamed = false;
        $hadUnpack = false;
        foreach ($args as $arg) {
            $argName = $this->callArgName($arg);
            $isNamed = null !== $argName;
            $isUnpack = $this->callArgUnpack($arg);
            if ($isUnpack && $hadNamed) {
                $this->throwCompileError('Cannot use argument unpacking after named arguments');
            }
            if (!$isNamed && !$isUnpack && $hadNamed) {
                $this->throwCompileError('Cannot use positional argument after named argument');
            }
            if (!$isNamed && !$isUnpack && $hadUnpack) {
                $this->throwCompileError('Cannot use positional argument after argument unpacking');
            }
            if ($isNamed) {
                $hadNamed = true;
            }
            if ($isUnpack) {
                $hadUnpack = true;
            }
        }
    }

    private function callArgName(Operand $arg): ?string
    {
        if (property_exists($arg, 'callArgName') && null !== $arg->callArgName) {
            $name = $arg->callArgName;

            return is_string($name) && '' !== $name ? $name : null;
        }

        return null;
    }

    /**
     * CFG call argument expression for hoisted producer wiring (#16057, #18410).
     */
    private function cfgCallArgOperand(?Op $cfgCallOp, int $argIndex, $loopArg): ?Operand
    {
        if (null !== $cfgCallOp && property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args)) {
            $cfgArg = $cfgCallOp->args[$argIndex] ?? null;
            if ($cfgArg instanceof Operand) {
                return $cfgArg;
            }
        }
        if ($loopArg instanceof Operand) {
            return $loopArg;
        }

        return null;
    }

    /**
     * Constant slot for TYPE_ARG_SEND named-parameter label (#11052, #12018).
     */
    private function callArgNameSlot(Operand $arg, Block $block): ?string
    {
        $argName = $this->callArgName($arg);
        if (null === $argName) {
            return null;
        }
        $nameOp = new Operand\Literal($argName);
        $nameOp->type = Type::string();
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($argName);

        return $block->registerConstant($nameOp, $nameVar);
    }

    /**
     * True when any call operand carries a php-cfg named-parameter label (#11052, #11105).
     */
    private function callIncludesNamedParameter(?Op $cfgCallOp): bool
    {
        if (null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $arg) {
            if ($arg instanceof Operand && null !== $this->callArgName($arg)) {
                return true;
            }
        }

        return false;
    }


    /**
     * True when a Plus/Minus(read, 1) + Assign(write) pair is lowered ++/-- (#3469).
     *
     * php-cfg uses dedicated PostInc/PreInc/PostDec/PreDec ops (#3552), not Plus+Assign.
     * AssignOp ($x += 1 / $x -= 1) shares the Plus(var,1)+Assign shape and must not set
     * {@see OpCode::$isIncDec} — bool compound assign promotes to int, ++/-- does not (#7340).
     */
    private function isIncDecBinaryOp(Op\Expr\BinaryOp $expr): bool
    {
        return false;
    }

    private function operandsSameBaseVariable(?Operand $left, ?Operand $right): bool
    {
        $leftName = $this->baseVariableName($left);
        $rightName = $this->baseVariableName($right);
        if (null === $leftName || null === $rightName) {
            return false;
        }

        return $leftName === $rightName;
    }

    private function baseVariableName(?Operand $operand): ?string
    {
        while ($operand instanceof Temporary && $operand->original instanceof Operand) {
            $operand = $operand->original;
        }
        if ($operand instanceof BoundVariable && $operand->name instanceof Literal && is_string($operand->name->value)) {
            return $operand->name->value;
        }
        if ($operand instanceof CfgVariable && $operand->name instanceof Literal && is_string($operand->name->value)) {
            return $operand->name->value;
        }

        return null;
    }
}
