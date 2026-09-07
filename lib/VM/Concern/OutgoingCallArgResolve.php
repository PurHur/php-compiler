<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\Func;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\Variable;

/**
 * Outgoing call-arg resolve / merge / by-ref separation for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code resolveOutgoingCallArgs} through
 * {@code internalBuiltinFunctionName} (php-src Zend/zend_execute.c SEND_* and fcall
 * arg resolve; named args via zend_execute_API; by-ref COW separation for internals).
 * Companion to {@see OutgoingCallTempRelease}. Concern trait — same namespace as
 * parent so relative Frame / OpCode / Block helpers resolve. Move-only; no new C ABI.
 */
trait OutgoingCallArgResolve
{
    /**
     * @return list<Variable>
     */
    private function resolveOutgoingCallArgs(Frame $frame): array
    {
        if (null === $frame->call) {
            return $frame->callArgs;
        }

        if (null !== $frame->magicCallMethodName) {
            // Zend: __call/__callStatic receive ($name, $arguments) where $arguments
            // preserves named-arg string keys — do not resolve against __call params (#23336).
            $methodName = $frame->magicCallMethodName;
            $frame->magicCallMethodName = null;
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($methodName);
            $argsVar = VM\MagicCallArgs::packUserArguments($this, $frame);

            $args = array_merge($frame->callArgs, [$nameVar, $argsVar]);
            $this->separateInternalByRefArgsForWrite(
                $frame->call,
                $args,
                $frame->builtinCalleeQualifiedMethod
            );

            return $args;
        }

        [$paramNames, $variadicIndex] = $this->calleeParamMetadata($frame->call, $frame);

        $userArgs = $this->resolveUserCallArgs(
            $frame,
            $paramNames,
            $variadicIndex,
            $this->internalBuiltinFunctionName($frame->call, $frame),
            $frame->call instanceof Func\Internal
        );
        if ([] === $frame->callArgs) {
            $this->separateInternalByRefArgsForWrite(
                $frame->call,
                $userArgs,
                $frame->builtinCalleeQualifiedMethod
            );

            return $userArgs;
        }

        $args = $this->mergeOutgoingCallArgs($frame->callArgs, $userArgs);
        $this->separateInternalByRefArgsForWrite(
            $frame->call,
            $args,
            $frame->builtinCalleeQualifiedMethod
        );

        return $args;
    }

    /**
     * Merge implicit call prefix ($this on new/method calls) with user args without
     * renumbering named-parameter indices (issue #11844, Zend/zend_execute.c).
     *
     * @param list<Variable>        $prefix
     * @param array<int, Variable>  $userArgs
     *
     * @return array<int, Variable>
     */
    private function mergeOutgoingCallArgs(array $prefix, array $userArgs): array
    {
        if ([] === $prefix) {
            return $userArgs;
        }
        if ([] === $userArgs) {
            return $prefix;
        }

        $args = $prefix;
        $offset = \count($prefix);
        foreach ($userArgs as $idx => $value) {
            $args[$offset + (int) $idx] = $value;
        }

        return $args;
    }

    /**
     * COW-separate array zvals passed by reference to internal builtins (Zend zval separation, #6689).
     *
     * @param list<Variable> $calledArgs
     */
    /**
     * @param list<Variable> $calledArgs
     */
    private function separateInternalByRefArgsForWrite(Func $call, array $calledArgs, ?string $qualifiedMethod = null): void
    {
        if (!$call instanceof Func\Internal) {
            return;
        }
        $name = $qualifiedMethod ?? $call->getName();
        foreach (BuiltinByRefParams::forFunction($name) as $idx) {
            if (isset($calledArgs[$idx])) {
                $calledArgs[$idx]->separateArrayForWrite();
            }
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($name);
        if (null === $variadicFrom) {
            return;
        }
        $n = \count($calledArgs);
        for ($i = $variadicFrom; $i < $n; ++$i) {
            if (
                isset($calledArgs[$i])
                && BuiltinByRefParams::isByRefArg($name, $i, $calledArgs[$i])
            ) {
                $calledArgs[$i]->separateArrayForWrite();
            }
        }
    }

    /**
     * Resolve an operand slot — use compile-time constants when scope is unset or clobbered (#5933, #5636).
     */
    private function resolveOutgoingCallArgValue(Frame $frame, int $slot): Variable
    {
        $const = null;
        if (null !== $frame->block && isset($frame->block->constants[$slot])) {
            $const = $frame->block->constants[$slot];
        }
        if (isset($frame->scope[$slot])) {
            // Zend CV init: explicit NULL must not lose to block constants from skipped list dim temps (#10507).
            if (isset($frame->initializedSlots[$slot])) {
                return $frame->scope[$slot];
            }
            // Named locals must stay tied to scope for by-ref outgoing calls (#9505, #9700).
            if (null !== $frame->block && $frame->block->isNamedVariableSlot($slot)) {
                return $frame->scope[$slot];
            }
            $resolved = $frame->scope[$slot]->resolveIndirect();
            if (null !== $const && $this->isImmortalEnumCaseBlockConstant($const)) {
                if (VM\EnumCaseSupport::isEnumCaseVariable($resolved)) {
                    return $frame->scope[$slot];
                }
                // Enum case ->name/->value in call args reuse the case slot; prefer the
                // property-fetch runtime value over immortal enum const (#9684, zend_enum.c).
                if ($resolved->isUndefined() || Variable::TYPE_NULL === $resolved->type) {
                    $value = new Variable();
                    $value->copyFrom($const);

                    return $value;
                }

                return $frame->scope[$slot];
            }
            if (Variable::TYPE_NULL !== $resolved->type && !$resolved->isUndefined()) {
                if (
                    Variable::TYPE_OBJECT === $resolved->type
                    && null !== $const
                    && Variable::TYPE_ARRAY === $const->type
                ) {
                    return $frame->scope[$slot];
                }
                if (null === $const || $resolved->type === $const->type) {
                    return $frame->scope[$slot];
                }
                // Object-cast assign ($a = (object)[...]) keeps array block constants on the CV slot (#15874).
                if (null !== $frame->block) {
                    $operand = $frame->block->operandForScopeSlot($slot);
                    if (null !== $operand && null !== Block::resolveVariableName($operand)) {
                        return $frame->scope[$slot];
                    }
                }
                // Array dim fetch / spread temps hold live objects; do not substitute NULL block constants (#8814).
                if (!$this->isEnumSlotClobberCandidate($resolved)) {
                    return $frame->scope[$slot];
                }
            }
        }
        if (null !== $const) {
            if (null !== $frame->block) {
                $operand = $frame->block->operandForScopeSlot($slot);
                if (null !== $operand && null !== Block::resolveVariableName($operand)) {
                    $resolved = $frame->scope[$slot]->resolveIndirect();
                    if (Variable::TYPE_NULL !== $resolved->type && !$resolved->isUndefined()) {
                        return $frame->scope[$slot];
                    }
                }
            }
            $value = new Variable();
            $value->copyFrom($const);

            return $value;
        }

        return $frame->scope[$slot];
    }

    /**
     * Whether an outgoing call argument binds by reference (Zend ZEND_SEND_REF).
     */
    private function outgoingCallArgNeedsReference(Frame $frame, int $argIndex, ?Variable $value = null): bool
    {
        if (null === $frame->call) {
            return false;
        }
        if ($frame->call instanceof Func\Internal) {
            // Prefer Class::method so instance &$params use the correct index (#5747 Collator::asort).
            $name = $frame->builtinCalleeQualifiedMethod ?? $frame->call->getName();

            return BuiltinByRefParams::isByRefArg($name, $argIndex, $value);
        }
        if ($frame->call instanceof Func\PHP) {
            $block = $frame->call->block;
            if ([] === $block->paramByRef) {
                return false;
            }
            $thisArgOffset = 0;
            if (
                null !== $block->func
                && null !== $block->func->class
                && !(($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
                && !(($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
            ) {
                $thisArgOffset = 1;
            }
            $paramIdx = $argIndex - $thisArgOffset;
            if (isset($block->paramByRef[$paramIdx])) {
                if (
                    null !== $block->variadicParamIndex
                    && $paramIdx === $block->variadicParamIndex
                ) {
                    return VM\ReferencableCheck::outgoingUserArgNeedsVariadicByRef(
                        $block,
                        $argIndex,
                        $thisArgOffset,
                        $argIndex + 1
                    );
                }

                return true;
            }

            return VM\ReferencableCheck::outgoingUserArgNeedsVariadicByRef(
                $block,
                $argIndex,
                $thisArgOffset,
                $argIndex + 1
            );
        }

        return false;
    }

    private function isImmortalEnumCaseBlockConstant(Variable $const): bool
    {
        if (Variable::TYPE_ENUM_CASE === $const->type) {
            return true;
        }

        return Variable::TYPE_OBJECT === $const->type
            && VM\EnumCaseSupport::isEnumCaseVariable($const);
    }

    /**
     * Scalar types that may clobber an enum-case scope slot (#5636); not resources/objects/arrays (#6204).
     */
    private function isEnumSlotClobberCandidate(Variable $resolved): bool
    {
        if (VM\ResourceSupport::isVmResource($resolved)) {
            return false;
        }
        if (VM\EnumCaseSupport::isEnumCaseVariable($resolved)) {
            return false;
        }

        return \in_array($resolved->type, [
            Variable::TYPE_NULL,
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_FLOAT,
            Variable::TYPE_STRING,
        ], true);
    }

    /**
     * @param list<string> $paramNames
     *
     * @return list<Variable>
     */
    private function resolveUserCallArgs(
        Frame $frame,
        array $paramNames,
        ?int $variadicIndex,
        ?string $functionName = null,
        bool $internalFunction = false
    ): array {
        if ([] === $frame->callArgEntries) {
            return [];
        }

        $entries = [];
        foreach ($frame->callArgEntries as $entry) {
            if ('u' === $entry[0]) {
                foreach (
                    VM\CallUnpack::expandToEntries(
                        $this,
                        $frame,
                        $entry[1],
                        $paramNames,
                        $variadicIndex,
                        $functionName
                    ) as $expanded
                ) {
                    $entries[] = $expanded;
                }
                continue;
            }
            $entries[] = $entry;
        }

        return NamedArgs::resolve($entries, $paramNames, $variadicIndex, $functionName, $internalFunction);
    }

    /**
     * @return array{0: list<string>, 1: ?int}
     */
    private function calleeParamMetadata(Func $call, ?Frame $frame = null): array
    {
        if ($call instanceof Func\PHP) {
            return [$call->block->paramNames, $call->block->variadicParamIndex];
        }
        if ($call instanceof Func\Internal) {
            $qualified = $frame?->builtinCalleeQualifiedMethod;
            if (null !== $qualified) {
                // Explicit BuiltinParamNames table, then php-types InternalArgInfo (#25182).
                // Bare getName() ("saveXML") has no param table and rejects Zend named args.
                $names = BuiltinParamNames::paramNamesForInternalFunction($qualified);
                if (null !== $names) {
                    return [
                        $names,
                        BuiltinParamNames::variadicParamIndexForFunction(strtolower($qualified)),
                    ];
                }
            }
            $name = $call->getName();

            return [
                BuiltinParamNames::paramNamesForInternalFunction($name) ?? [],
                BuiltinParamNames::variadicParamIndexForFunction($name),
            ];
        }

        return [[], null];
    }

    private function internalBuiltinFunctionName(Func $call, ?Frame $frame = null): ?string
    {
        if (!$call instanceof Func\Internal) {
            return null;
        }
        if (null !== $frame?->builtinCalleeQualifiedMethod) {
            return $frame->builtinCalleeQualifiedMethod;
        }

        return $call->getName();
    }
}
