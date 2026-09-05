<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Construct-frame / call-result assigned marks and native-long inc/dec locals (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code markJitThisConstructedIfLeavingConstruct}
 * through {@code materializeNamedNativeLongLocalForIncDec} so the hub keeps shrinking
 * toward gen-0 split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c object construction / by-ref out-param assignment and
 * typed local inc/dec (zend_operators) — move-only Concern extract; no new C ABI and
 * no opcode/IR shape change.
 */
trait JitConstructAssignedAndNativeLongLocal
{
    private function markJitThisConstructedIfLeavingConstruct(Block $block): void
    {
        if (!$this->isJitConstructFrame($block)) {
            return;
        }
        $thisVar = $this->resolveThisVariable($block);
        if (null === $thisVar || Variable::TYPE_OBJECT !== $thisVar->type) {
            return;
        }
        $this->context->type->object->markObjectConstructed(
            $this->context->helper->loadValue($thisVar)
        );
    }

    private function isJitConstructFrame(Block $block): bool
    {
        $func = $block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    /** Void JIT __construct Call proxies whose EXEC_RETURN must not wipe `new` (#23641). */
    private function isVoidJitConstructCall(?JIT\Call $toCall): bool
    {
        if (null === $toCall) {
            return false;
        }
        if ($toCall instanceof JIT\Call\ExceptionConstruct) {
            return true;
        }
        if (
            $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            && '__construct' === $toCall->methodLc
        ) {
            return true;
        }
        if (
            $toCall instanceof JIT\Call\NoOpConstruct
        ) {
            return true;
        }
        if ($toCall instanceof JIT\Call\ReflectionClassConstruct
            || $toCall instanceof JIT\Call\ReflectionObjectConstruct
            || $toCall instanceof JIT\Call\ReflectionFunctionConstruct
            || $toCall instanceof JIT\Call\ReflectionExtensionConstruct
            || $toCall instanceof JIT\Call\ReflectionParameterConstruct
            || $toCall instanceof JIT\Call\ReflectionPropertyConstruct
            || $toCall instanceof JIT\Call\ReflectionMethodConstruct
            || $toCall instanceof JIT\Call\ReflectionClassConstantConstruct
            || $toCall instanceof JIT\Call\ReflectionConstantConstruct
            || $toCall instanceof JIT\Call\ReflectionEnumConstruct
            || $toCall instanceof JIT\Call\RandomizerConstruct
            || $toCall instanceof JIT\Call\SimpleXMLElementConstruct
            || $toCall instanceof JIT\Call\BcMathNumberConstruct
            || $toCall instanceof JIT\Call\SensitiveParameterValueConstruct
            || $toCall instanceof JIT\Call\DateTimeConstruct
            || $toCall instanceof JIT\Call\DateTimeImmutableConstruct
            || $toCall instanceof JIT\Call\DateTimeZoneConstruct
            || $toCall instanceof JIT\Call\DateIntervalConstruct
            || $toCall instanceof JIT\Call\DatePeriodConstruct
            || $toCall instanceof JIT\Call\DomDocumentConstruct
            || $toCall instanceof JIT\Call\ZipArchiveConstruct
            || $toCall instanceof JIT\Call\PdoConstruct
            || $toCall instanceof JIT\Call\ArrayIteratorConstruct
            || $toCall instanceof JIT\Call\RecursiveIteratorIteratorConstruct
            || $toCall instanceof JIT\Call\LimitIteratorConstruct
            || $toCall instanceof JIT\Call\RegexIteratorConstruct
            || $toCall instanceof JIT\Call\CallbackFilterIteratorConstruct
            || $toCall instanceof JIT\Call\CachingIteratorConstruct
            || $toCall instanceof JIT\Call\ParentIteratorConstruct
            || $toCall instanceof JIT\Call\RecursiveTreeIteratorConstruct
            || ($toCall instanceof JIT\Call\AppendIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\MultipleIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplHtPosIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\EmptyIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\FilterIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplHeapMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplPriorityQueueMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplDllistMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplFixedArrayMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\DirectoryIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\SplFileObjectMethod
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\Sqlite3Method
                && '__construct' === strtolower($toCall->methodName()))
            || ($toCall instanceof JIT\Call\GlobIteratorMethod
                && '__construct' === strtolower($toCall->methodName()))
        ) {
            return true;
        }
        if ($toCall instanceof JIT\Call\Native || $toCall instanceof JIT\Call\ExternalMethod) {
            $name = strtolower(
                $toCall instanceof JIT\Call\Native ? $toCall->name : $toCall->proxyName
            );

            return str_ends_with($name, '::__construct');
        }

        return false;
    }

    /**
     * Like {@see isVoidJitConstructCall} but DateTime ctors return the initialized object box (#35752).
     */
    private function isVoidJitConstructCallThatDiscardsExecReturn(?JIT\Call $toCall): bool
    {
        if (!$this->isVoidJitConstructCall($toCall)) {
            return false;
        }
        if (
            $toCall instanceof JIT\Call\DateTimeConstruct
            || $toCall instanceof JIT\Call\DateTimeImmutableConstruct
        ) {
            return false;
        }

        return true;
    }

    /**
     * After a call with by-ref out parameters, mark those CVs assigned so later
     * ZEND_CHECK_UNDEFINED_VAR stays quiet (#36081 regression on j08_preg / preg_match $matches).
     *
     * php-src: zend_execute.c — callee write through ZEND_SEND_REF defines the CV.
     *
     * @param list<Operand> $callOperands
     */
    private function markByRefOutParamsAssignedAfterCall(
        ?JIT\Call $toCall,
        array $callOperands,
        Block $block
    ): void {
        if (null === $toCall) {
            return;
        }
        $byRefIndices = [];
        if ($toCall instanceof CoreFunc\Internal) {
            $name = $toCall->getName();
            $byRefIndices = BuiltinByRefParams::forFunction($name);
            $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($name);
            if (null !== $variadicFrom) {
                for ($idx = $variadicFrom, $n = \count($callOperands); $idx < $n; ++$idx) {
                    $byRefIndices[] = $idx;
                }
            }
        } elseif ($toCall instanceof JIT\Call\Native) {
            foreach ($toCall->paramByRefByArg as $idx => $_) {
                if (null !== $toCall->variadicArgIndex && $idx === $toCall->variadicArgIndex) {
                    continue;
                }
                $byRefIndices[] = $idx;
            }
            if (
                null !== $toCall->variadicArgIndex
                && isset($toCall->paramByRefByArg[$toCall->variadicArgIndex])
            ) {
                $start = $toCall->variadicArgIndex;
                $end = \count($callOperands) - 1;
                if (null !== $toCall->namedArgsVariadicIndex) {
                    $trailing = \count($toCall->paramNames) - $toCall->namedArgsVariadicIndex - 1;
                    if ($trailing > 0) {
                        $end = \count($callOperands) - $trailing - 1;
                    }
                }
                for ($idx = $start; $idx <= $end; ++$idx) {
                    $byRefIndices[] = $idx;
                }
            }
        } else {
            return;
        }
        $seen = [];
        foreach ($byRefIndices as $idx) {
            if (isset($seen[$idx])) {
                continue;
            }
            $seen[$idx] = true;
            $operand = $callOperands[$idx] ?? null;
            if (null === $operand) {
                continue;
            }
            $this->markByRefOutParamOperandAssigned($block, $operand);
        }
    }

    private function markByRefOutParamOperandAssigned(Block $block, Operand $operand): void
    {
        if (!$this->context->hasVariableOp($operand)) {
            $this->context->aliasVariableOpFromSlot($block, $operand);
        }
        if (!$this->context->hasVariableOp($operand)) {
            return;
        }
        $var = $this->context->getVariableFromOp($operand);
        JIT\UndefinedVariableHelper::markAssigned($this->context, $operand, $var);
    }

    /** True when the pending outgoing call passes argument $argIndex by reference (ZEND_SEND_REF). */
    private function isOutgoingByRefArgIndex(?JIT\Call $toCall, int $argIndex): bool
    {
        if (null === $toCall) {
            return false;
        }
        if ($toCall instanceof CoreFunc\Internal) {
            return BuiltinByRefParams::isByRefArg($toCall->getName(), $argIndex);
        }
        if ($toCall instanceof JIT\Call\Native) {
            if (isset($toCall->paramByRefByArg[$argIndex])) {
                return true;
            }
            if (
                null !== $toCall->variadicArgIndex
                && isset($toCall->paramByRefByArg[$toCall->variadicArgIndex])
                && $argIndex >= $toCall->variadicArgIndex
            ) {
                $end = $toCall->variadicArgIndex;
                if (null !== $toCall->namedArgsVariadicIndex) {
                    $trailing = \count($toCall->paramNames) - $toCall->namedArgsVariadicIndex - 1;
                    if ($trailing > 0) {
                        return $argIndex <= $end;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @param list<JIT\Variable|array{unpack: JIT\Variable}> $callArgs
     */
    private function markNewObjectConstructedAfterCall(?JIT\Call $toCall, array $callArgs): void
    {
        if (null === $toCall) {
            return;
        }
        if (
            $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            && '__construct' === $toCall->methodLc
        ) {
            if ([] === $callArgs) {
                return;
            }
            $first = $callArgs[0];
            if (is_array($first)) {
                $first = $first['unpack'] ?? null;
            }
            if (!$first instanceof JIT\Variable || Variable::TYPE_OBJECT !== $first->type) {
                return;
            }
            $this->context->type->object->markObjectConstructed(
                $this->context->helper->loadValue($first)
            );

            return;
        }
        if ($toCall instanceof JIT\Call\Native) {
            $name = strtolower($toCall->name);
        } elseif ($toCall instanceof JIT\Call\ExternalMethod) {
            $name = strtolower($toCall->proxyName);
        } elseif ($toCall instanceof JIT\Call\SimpleXMLElementConstruct) {
            $name = 'simplexmlelement::__construct';
        } elseif ($toCall instanceof JIT\Call\ExceptionConstruct) {
            $name = 'exception::__construct';
        } elseif ($this->isVoidJitConstructCall($toCall)) {
            $name = '::__construct';
        } else {
            return;
        }
        if (!str_ends_with($name, '::__construct')) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $first = $callArgs[0];
        if (is_array($first)) {
            $first = $first['unpack'] ?? null;
        }
        if (!$first instanceof JIT\Variable || Variable::TYPE_OBJECT !== $first->type) {
            return;
        }
        $this->context->type->object->markObjectConstructed(
            $this->context->helper->loadValue($first)
        );
    }

    private function jitTypeFromLlvmValue(PHPLLVM\Value $value): int
    {
        switch ($this->context->getStringFromType($value->typeOf())) {
            case 'double':
                return Variable::TYPE_NATIVE_DOUBLE;
            case 'int1':
            case 'bool':
                return Variable::TYPE_NATIVE_BOOL;
            case 'int64':
            case 'long long':
            case 'int32':
            case 'size_t':
            case 'unsigned int':
                return Variable::TYPE_NATIVE_LONG;
            case '__string__*':
                return Variable::TYPE_STRING;
            case '__object__*':
                return Variable::TYPE_OBJECT;
            case '__hashtable__*':
                return Variable::TYPE_HASHTABLE;
            case '__value__':
            case '__value__*':
                return Variable::TYPE_VALUE;
            default:
                throw new \LogicException(
                    'Cannot infer JIT variable type from LLVM type: '
                    .$this->context->getStringFromType($value->typeOf())
                );
        }
    }

    /**
     * Promote named function locals from stale KIND_VALUE i64 literals to an alloca (#36018).
     */
    private function ensureNamedNativeLongLocalAlloca(Operand $resultOp, JIT\Variable $result): JIT\Variable
    {
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if (Variable::KIND_VARIABLE === $bound->kind && Variable::TYPE_NATIVE_LONG === $bound->type) {
                    $this->context->setVariableOp($resultOp, $bound);

                    return $bound;
                }
            }
        }
        if (Variable::KIND_VARIABLE === $result->kind && Variable::TYPE_NATIVE_LONG === $result->type) {
            return $result;
        }
        if (Variable::TYPE_NATIVE_LONG !== $result->type || Variable::KIND_VALUE !== $result->kind) {
            return $result;
        }
        if (null === $result->value || \PHPLLVM\Value::KIND_CONSTANT_INT !== $result->value->getKind()) {
            return $result;
        }
        if (null === $name || '' === $name) {
            return $result;
        }
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return $result;
        }
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $this->context->builder->store($this->context->helper->loadValue($result), $slot);
        $allocaVar = new JIT\Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $allocaVar->addref();
        $allocaVar->compileTimeLong = null;
        $this->context->setVariableOp($resultOp, $allocaVar);
        $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $allocaVar);

        return $allocaVar;
    }

    /**
     * Named locals ($i++) must not constant-fold on a stale LLVM i64 literal — loop
     * JUMPIF still reads the original slot (#36018 / peer #32605 / #32831).
     */
    private function isNamedLocalIncDec(Operand $readOp, Operand $writeOp): bool
    {
        $name = JIT\OperandName::resolve($readOp) ?? JIT\OperandName::resolve($writeOp);
        if (null === $name || '' === $name) {
            return false;
        }
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return false;
        }

        return true;
    }

    /**
     * Promote `$i = 0; …; $i++` locals from KIND_VALUE i64 literals to an alloca so
     * loop headers and post-increment share one mutable slot (#36018).
     *
     * @return array{0: JIT\Variable, 1: JIT\Variable}
     */
    private function materializeNamedNativeLongLocalForIncDec(
        Operand $readOp,
        Operand $writeOp,
        JIT\Variable $read,
        JIT\Variable $write
    ): array {
        if (!$this->isNamedLocalIncDec($readOp, $writeOp)) {
            return [$read, $write];
        }
        if (Variable::KIND_VARIABLE === $write->kind && Variable::TYPE_NATIVE_LONG === $write->type) {
            return [$read, $write];
        }
        if (Variable::TYPE_NATIVE_LONG !== $read->type || Variable::TYPE_NATIVE_LONG !== $write->type) {
            return [$read, $write];
        }
        if (Variable::KIND_VALUE !== $write->kind || null === $write->value) {
            return [$read, $write];
        }
        if (\PHPLLVM\Value::KIND_CONSTANT_INT !== $write->value->getKind()) {
            return [$read, $write];
        }
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $cur = $this->context->helper->loadValue($read);
        $this->context->builder->store($cur, $slot);
        $allocaVar = new JIT\Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $allocaVar->addref();
        $allocaVar->compileTimeLong = null;
        $name = JIT\OperandName::resolve($writeOp) ?? JIT\OperandName::resolve($readOp);
        if (null !== $name && '' !== $name) {
            $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $allocaVar);
        }
        $this->context->setVariableOp($writeOp, $allocaVar);
        if ($readOp !== $writeOp) {
            $this->context->setVariableOp($readOp, $allocaVar);
        }

        return [$allocaVar, $allocaVar];
    }
}
