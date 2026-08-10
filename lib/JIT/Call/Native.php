<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/lib/JIT/Call/Native.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Builtin\CallArgv;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;

use PHPLLVM\Value;

class Native implements Call {

    public Value $function;
    public string $name;
    public array $argTypes;

    /** @var array<int, Variable> compile-time defaults for optional parameters */
    public array $defaultArgs = [];

    /** LLVM argument index of the variadic ...$param slot, if any (issue #197). */
    public ?int $variadicArgIndex = null;

    /** @var array<int, int> LLVM arg index => VM scalar type constraint (issue #1229) */
    public array $paramTypeConstraintsByArg = [];

    /** @var array<int, list<string>> LLVM arg index => intersection interface lc names (#3077) */
    public array $paramIntersectionConstraintsByArg = [];

    /** @var array<int, list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>> LLVM arg index => DNF arms (#4008) */
    public array $paramDnfConstraintsByArg = [];

    /** @var array<int, string> LLVM arg index => class/interface name (#6145) */
    public array $paramClassConstraintsByArg = [];

    /** LLVM arg index => by-reference formal (issue #3161, #140). @var array<int, true> */
    public array $paramByRefByArg = [];

    /** Declared parameter names by index (issue #3777). */
    public array $paramNames = [];

    /** PHP variadic parameter index for named-arg resolution (issue #3777). */
    public ?int $namedArgsVariadicIndex = null;

    /** LLVM arg index => implicit nullable (`int $x = null`, #4449). */
    public array $paramImplicitNullableByArg = [];

    /** When false, skip CallArgv hashtable pack at call sites (#15907 perf). */
    public bool $emitCallArgv = true;

    public function __construct(
        Value $function,
        string $name,
        array $argTypes,
        array $defaultArgs = [],
        ?int $variadicArgIndex = null,
        array $paramTypeConstraintsByArg = [],
        array $paramIntersectionConstraintsByArg = [],
        array $paramDnfConstraintsByArg = [],
        array $paramClassConstraintsByArg = [],
        array $paramByRefByArg = [],
        array $paramNames = [],
        ?int $namedArgsVariadicIndex = null,
        array $paramImplicitNullableByArg = [],
        bool $emitCallArgv = true
    ) {
        $this->function = $function;
        $this->name = $name;
        $this->argTypes = $argTypes;
        $this->defaultArgs = $defaultArgs;
        $this->variadicArgIndex = $variadicArgIndex;
        $this->paramTypeConstraintsByArg = $paramTypeConstraintsByArg;
        $this->paramIntersectionConstraintsByArg = $paramIntersectionConstraintsByArg;
        $this->paramDnfConstraintsByArg = $paramDnfConstraintsByArg;
        $this->paramClassConstraintsByArg = $paramClassConstraintsByArg;
        $this->paramByRefByArg = $paramByRefByArg;
        $this->paramNames = $paramNames;
        $this->namedArgsVariadicIndex = $namedArgsVariadicIndex;
        $this->paramImplicitNullableByArg = $paramImplicitNullableByArg;
        $this->emitCallArgv = $emitCallArgv;
    }

    public function call(Context $context, Variable ... $args): Value {
        return $this->callWithArgMap($context, $args);
    }

    /**
     * Invoke with a parameter-index map so named-arg holes survive.
     *
     * PHP's `...$sparse` unpack renumbers integer keys, which turns `n(b: 7)` into
     * a positional first argument. NamedArgs keeps param indices; pass that map here (#23972).
     *
     * @param array<int, Variable> $args
     */
    public function callWithArgMap(Context $context, array $args): Value {
        ksort($args);
        $this->rejectSkippedEffectivelyRequiredArgs($context, $args);
        $this->rejectTooFewPositionalArgs($context, $args);
        // CallArgv: parameter-index order with defaults for skipped named optionals (#24948).
        // Keep $args sparse for RECV / LLVM binding; only the func_* snapshot is densified.
        $sentArgs = $this->densifyCallArgvArgs($args);
        /** @var list<Variable>|null $byRefVariadicCallers */
        $byRefVariadicCallers = null;
        $byRefVariadicPacked = null;
        if (
            null !== $this->variadicArgIndex
            && isset($this->paramByRefByArg[$this->variadicArgIndex])
        ) {
            $byRefVariadicCallers = $this->collectByRefVariadicCallerArgs($args);
        }
        if (null !== $this->variadicArgIndex) {
            $this->enforceVariadicTrailingArgs($context, $args);
            $args = $this->packVariadicCallArgs($context, $args);
            if (null !== $byRefVariadicCallers && isset($args[$this->variadicArgIndex])) {
                $byRefVariadicPacked = $args[$this->variadicArgIndex];
            }
        }
        if ($this->emitCallArgv) {
            // Store call-site argv for func_get_args/func_num_args (issue #197).
            CallArgv::emitStore($context, HashTableHelper::packVariables($context, $sentArgs));
        }
        $argValues = [];
        $total = count($this->argTypes);
        for ($index = 0; $index < $total; $index++) {
            if (isset($args[$index])) {
                $arg = $args[$index];
            } elseif (isset($this->defaultArgs[$index])) {
                $arg = $this->defaultArgs[$index];
            } else {
                $arg = $this->missingCallArg($context, $this->argTypes[$index]);
            }
            $skipVariadicPackedTypeCheck = null !== $this->variadicArgIndex
                && $index === $this->variadicArgIndex
                && $this->variadicSlotUsesElementTypeChecks($index);
            if (
                !$skipVariadicPackedTypeCheck
                && isset($this->paramTypeConstraintsByArg[$index])
                && !$this->skipImplicitNullableTypeCheck($index, $arg)
            ) {
                $constraint = $this->paramTypeConstraintsByArg[$index];
                if ($context->callerStrictTypes) {
                    \PHPCompiler\JIT\TypeCheck::enforceParameter(
                        $context,
                        $arg,
                        $constraint,
                        true
                    );
                } else {
                    $prefix = $this->receiverPrefix();
                    $userIdx = $index - $prefix;
                    $paramName = $this->paramNames[$userIdx] ?? ('param'.$userIdx);
                    $arg = \PHPCompiler\JIT\TypedParamCoerce::weakAtCallSite(
                        $context,
                        $arg,
                        $constraint,
                        $this->name,
                        $userIdx,
                        $paramName
                    );
                }
            }
            if (!$skipVariadicPackedTypeCheck && isset($this->paramIntersectionConstraintsByArg[$index])) {
                \PHPCompiler\JIT\IntersectionParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramIntersectionConstraintsByArg[$index]
                );
            }
            if (!$skipVariadicPackedTypeCheck && isset($this->paramDnfConstraintsByArg[$index])) {
                $prefix = $this->receiverPrefix();
                $userIdx = $index - $prefix;
                $paramName = $this->paramNames[$userIdx] ?? ('param'.$userIdx);
                \PHPCompiler\JIT\DnfParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramDnfConstraintsByArg[$index],
                    'Argument',
                    $this->name,
                    $userIdx,
                    $paramName
                );
            }
            if (!$skipVariadicPackedTypeCheck && isset($this->paramClassConstraintsByArg[$index])) {
                \PHPCompiler\JIT\ClassParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramClassConstraintsByArg[$index]
                );
            }
            $argValues[] = $this->compileArg($context, $arg, $index);
        }
        $result = $context->builder->call(
            $this->function,
            ...$argValues
        );
        // Pack copies values into the HT; sync element writes back to by-ref callers
        // after return (AOT script globals / #27407, Zend SEND_REF variadic).
        if (null !== $byRefVariadicCallers && null !== $byRefVariadicPacked) {
            $this->syncByRefVariadicCallers($context, $byRefVariadicPacked, $byRefVariadicCallers);
        }

        return $result;
    }

    /**
     * Densify sparse named-arg maps for CallArgv / func_* (Zend zend_execute.c, #24948).
     *
     * Skips an implicit $this / NEW receiver prefix. Fills holes up to max passed index with
     * {@see $defaultArgs} (indexed like LLVM params, including the receiver offset).
     * Does not fill effectively-required holes (optional-before-required, #25728) — those are
     * rejected in {@see rejectSkippedEffectivelyRequiredArgs()} before densify.
     *
     * @param array<int, Variable> $args
     *
     * @return list<Variable>
     */
    private function densifyCallArgvArgs(array $args): array
    {
        if ([] === $args) {
            return [];
        }
        $prefix = $this->receiverPrefix();
        $userArgs = [];
        foreach ($args as $idx => $var) {
            $i = (int) $idx;
            if ($i < $prefix) {
                continue;
            }
            $userArgs[$i - $prefix] = $var;
        }
        if ([] === $userArgs) {
            return [];
        }
        if (array_is_list($userArgs)) {
            return $userArgs;
        }
        $maxIdx = (int) max(array_keys($userArgs));
        $out = [];
        for ($i = 0; $i <= $maxIdx; ++$i) {
            if (isset($userArgs[$i])) {
                $out[] = $userArgs[$i];
                continue;
            }
            if ($this->userParamIsEffectivelyRequired($i)) {
                continue;
            }
            $defaultIdx = $prefix + $i;
            if (isset($this->defaultArgs[$defaultIdx])) {
                $out[] = $this->defaultArgs[$defaultIdx];
            }
        }

        return $out;
    }

    /**
     * Positional too-few-args → Zend "Too few arguments to function …" (zend_execute.c, #29746).
     *
     * @param array<int, Variable> $args
     */
    private function rejectTooFewPositionalArgs(Context $context, array $args): void
    {
        if ([] === $this->paramNames) {
            return;
        }
        $prefix = $this->receiverPrefix();
        $passed = 0;
        foreach ($args as $idx => $_) {
            if ((int) $idx < $prefix) {
                continue;
            }
            ++$passed;
        }
        $minRequired = $this->countMinimumRequiredUserParams();
        if ($passed >= $minRequired) {
            return;
        }
        $userCount = \count($this->paramNames);
        $hasTrailingOptional = $minRequired < $userCount;
        $expectedPhrase = $hasTrailingOptional
            ? \sprintf('at least %d expected', $minRequired)
            : \sprintf('exactly %d expected', $minRequired);
        $block = $context->jitCurrentBlock;
        $scriptPath = null !== $block ? $block->scriptPath() : '';
        if ('' === $scriptPath) {
            $scriptPath = 'Standard input code';
        }
        $callSiteLine = max(1, $context->callSiteLine);
        $function = \PHPCompiler\VM\ParamArgumentCountError::formatUserFunctionName($this->name);
        \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
            $context,
            \sprintf(
                'Too few arguments to function %s(), %d passed in %s on line %d and %s',
                $function,
                $passed,
                $scriptPath,
                $callSiteLine,
                $expectedPhrase
            )
        );
    }

    /** Minimum user parameters that must be passed (zend_execute / #25728). */
    private function countMinimumRequiredUserParams(): int
    {
        $userCount = \count($this->paramNames);
        $required = $userCount;
        $prefix = $this->receiverPrefix();
        for ($i = $userCount - 1; $i >= 0; --$i) {
            if (null !== $this->namedArgsVariadicIndex && $i === $this->namedArgsVariadicIndex) {
                $required = $i;
                continue;
            }
            if (isset($this->defaultArgs[$prefix + $i])) {
                $required = $i;
                continue;
            }
            break;
        }

        return $required;
    }

    /**
     * Named-arg skip of optional-before-required → ArgumentCountError (zend_execute.c, #25728).
     *
     * @param array<int, Variable> $args
     */
    private function rejectSkippedEffectivelyRequiredArgs(Context $context, array $args): void
    {
        if ([] === $this->paramNames || [] === $args) {
            return;
        }
        $prefix = $this->receiverPrefix();
        $maxUser = -1;
        foreach ($args as $idx => $_) {
            $i = (int) $idx;
            if ($i < $prefix) {
                continue;
            }
            $maxUser = max($maxUser, $i - $prefix);
        }
        if ($maxUser < 0) {
            return;
        }
        $userCount = \count($this->paramNames);
        for ($userIdx = 0; $userIdx < $userCount && $userIdx <= $maxUser; ++$userIdx) {
            $llvmIdx = $prefix + $userIdx;
            if (isset($args[$llvmIdx])) {
                continue;
            }
            if (!$this->userParamIsEffectivelyRequired($userIdx)) {
                continue;
            }
            // Later user arg present ⇒ named omission of an effectively-required param.
            if ($maxUser > $userIdx) {
                $name = $this->paramNames[$userIdx] ?? '';
                \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                    $context,
                    \sprintf(
                        '%s(): Argument #%d ($%s) not passed',
                        $this->name,
                        $userIdx + 1,
                        $name
                    )
                );
            }
        }
    }

    private function receiverPrefix(): int
    {
        if (
            [] !== $this->paramNames
            && \count($this->argTypes) === \count($this->paramNames) + 1
        ) {
            return 1;
        }

        return 0;
    }

    /**
     * User param must be passed: no compile-time default, or default before a later required (#25728).
     */
    private function userParamIsEffectivelyRequired(int $userIdx): bool
    {
        if (null !== $this->namedArgsVariadicIndex && $userIdx === $this->namedArgsVariadicIndex) {
            return false;
        }
        $prefix = $this->receiverPrefix();
        $hasDefault = isset($this->defaultArgs[$prefix + $userIdx]);
        if (!$hasDefault) {
            return true;
        }
        $userCount = \count($this->paramNames);
        for ($j = $userIdx + 1; $j < $userCount; ++$j) {
            if (null !== $this->namedArgsVariadicIndex && $j === $this->namedArgsVariadicIndex) {
                return false;
            }
            if (!isset($this->defaultArgs[$prefix + $j])) {
                return true;
            }
        }

        return false;
    }

    protected function compileArg(Context $context, Variable $arg, int $argNum): Value {
        // Use the LLVM function's declared param type (argTypes can disagree for CFG Operand handles, #1056).
        $type = $this->function->getParam($argNum)->typeOf();
        $typeName = $context->getStringFromType($type);
        $value = $context->helper->loadValue($arg);
        switch ($typeName) {
            case '__object__*':
                if (
                    null !== $arg->objectPropertySlot
                    && Variable::TYPE_VALUE === $arg->objectPropertyType
                ) {
                    return $context->builder->call(
                        $context->lookupFunction('__value__readObject'),
                        $value
                    );
                }
                $valueTy = $value->typeOf();
                $valueTyName = $context->getStringFromType($valueTy);
                if (
                    '__value__*' === $valueTyName
                    || (
                        \PHPLLVM\Type::KIND_POINTER === $valueTy->getKind()
                        && '__value__' === $context->getStringFromType($valueTy->getElementType())
                    )
                ) {
                    return $context->builder->call(
                        $context->lookupFunction('__value__readObject'),
                        $value
                    );
                }
                switch ($arg->type) {
                    case Variable::TYPE_OBJECT:
                        if ('__value__*' === $valueTyName) {
                            return $context->builder->call(
                                $context->lookupFunction('__value__readObject'),
                                $value
                            );
                        }

                        return $value;
                    case Variable::TYPE_VALUE:
                        return $context->builder->call(
                            $context->lookupFunction('__value__readObject'),
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $arg)
                        );
                    case Variable::TYPE_NULL:
                        return $context->getTypeFromString('__object__*')->constNull();
                    case Variable::TYPE_HASHTABLE:
                        // Scope arrays may store VM Variable handles as hashtable pointers (issue #816).
                        return $context->builder->pointerCast(
                            $value,
                            $context->getTypeFromString('__object__*')
                        );
                    case Variable::TYPE_STRING:
                        return $context->getTypeFromString('__object__*')->constNull();
                }
                break;
            case '__hashtable__*':
                if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
                    return HashTableHelper::materializeNativeArrayForCall($context, $arg);
                }
                switch ($arg->type) {
                    case Variable::TYPE_HASHTABLE:
                        $context->refcount->addref($value);

                        return $value;
                    case Variable::TYPE_OBJECT:
                        // Self-host: spread/unpack may pass a single boxed array handle (issue #197).
                        return $context->builder->pointerCast(
                            $value,
                            $context->getTypeFromString('__hashtable__*')
                        );
                    case Variable::TYPE_VALUE:
                        return $context->builder->call(
                            $context->lookupFunction('__value__readHashtable'),
                            Variable::KIND_VARIABLE === $arg->kind
                                ? \PHPCompiler\JIT\JitValueBox::pointer($context, $arg->value)
                                : $value
                        );
                }
                break;
            case '__string__*':
                return JitStringArg::lower(
                    $context,
                    $arg,
                    "argument {$argNum} for {$this->name}()"
                );
            case '__value__*':
                if (null !== $arg->valueBoxAliasPtr) {
                    return \PHPCompiler\JIT\JitValueBox::normalizeValuePtr($context, $arg->valueBoxAliasPtr);
                }
                switch ($arg->type) {
                    case Variable::TYPE_VALUE:
                        return \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $arg);
                    case Variable::TYPE_NULL:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $context->builder->call(
                            $context->lookupFunction('__value__writeNull'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot)
                        );

                        return \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
                    case Variable::TYPE_STRING:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $owned = $context->builder->call(
                            $context->lookupFunction('__string__separate'),
                            $value
                        );
                        $context->builder->call(
                            $context->lookupFunction('__value__writeString'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $owned
                        );

                        return \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
                    case Variable::TYPE_OBJECT:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $context->builder->call(
                            $context->lookupFunction('__value__writeObject'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $value
                        );

                        return \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
                    case Variable::TYPE_NATIVE_LONG:
                    case Variable::TYPE_NATIVE_BOOL:
                    case Variable::TYPE_NATIVE_DOUBLE:
                    case Variable::TYPE_HASHTABLE:
                        return \PHPCompiler\JIT\JitValueBox::valuePtrFromNativeVariable($context, $arg);
                }
                break;
            case '__value__':
                switch ($arg->type) {
                    case Variable::TYPE_VALUE:
                        $valueTyName = $context->getStringFromType($value->typeOf());
                        if ('__value__*' === $valueTyName) {
                            return $context->builder->load($value);
                        }
                        if ('__value__' === $valueTyName) {
                            return $value;
                        }

                        return $context->builder->load(
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $arg)
                        );
                    case Variable::TYPE_OBJECT:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $context->builder->call(
                            $context->lookupFunction('__value__writeObject'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $value
                        );

                        return $context->builder->load($slot);
                    case Variable::TYPE_NATIVE_BOOL:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        \PHPCompiler\JIT\JitValueBox::writeBool(
                            $context,
                            $slot,
                            $context->builder->truncOrBitCast(
                                $value,
                                $context->getTypeFromString('int1')
                            )
                        );

                        return $context->builder->load($slot);
                    case Variable::TYPE_NATIVE_LONG:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $context->builder->call(
                            $context->lookupFunction('__value__writeLong'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $value
                        );

                        return $context->builder->load($slot);
                    case Variable::TYPE_NATIVE_DOUBLE:
                        // Peer of NATIVE_LONG above — NestedJIT RoundJitHelper (and similar)
                        // passes typed doubles into __value__ native params (#26921).
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $context->builder->call(
                            $context->lookupFunction('__value__writeDouble'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $value
                        );

                        return $context->builder->load($slot);
                    case Variable::TYPE_NULL:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $context->builder->call(
                            $context->lookupFunction('__value__writeNull'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot)
                        );

                        return $context->builder->load($slot);
                    case Variable::TYPE_HASHTABLE:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $context->builder->call(
                            $context->lookupFunction('__value__writeHashtable'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $value
                        );

                        return $context->builder->load($slot);
                    case Variable::TYPE_STRING:
                        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                        $owned = $context->builder->call(
                            $context->lookupFunction('__string__separate'),
                            $value
                        );
                        $context->builder->call(
                            $context->lookupFunction('__value__writeString'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $owned
                        );

                        return $context->builder->load($slot);
                }
                break;
            case 'int64':
                switch ($arg->type) {
                    case Variable::TYPE_NATIVE_LONG:
                        return $value;
                    case Variable::TYPE_NATIVE_BOOL:
                        return $context->builder->zExt(
                            $value,
                            $context->getTypeFromString('int64')
                        );
                    case Variable::TYPE_VALUE:
                        return $context->builder->call(
                            $context->lookupFunction('__value__readLong'),
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $arg)
                        );
                    case Variable::TYPE_OBJECT:
                        // Self-host stubs may return Block/__object__* where int slots are expected (#816).
                        return $context->builder->ptrToInt(
                            $value,
                            $context->getTypeFromString('int64')
                        );
                    case Variable::TYPE_NULL:
                        return $context->getTypeFromString('int64')->constInt(0, true);
                    case Variable::TYPE_STRING:
                        return (new \PHPCompiler\ext\standard\intval())->call($context, $arg);
                    case Variable::TYPE_NATIVE_DOUBLE:
                        // Weak typed int: zend_dval_to_lval_safe — INF/NAN → TypeError (#27925);
                        // finite precision loss → E_DEPRECATED (#23533).
                        return \PHPCompiler\ext\standard\JitIntdiv::floatToLongTypedSafe(
                            $context,
                            $value,
                            'Argument must be of type int, float given'
                        );
                }
                break;
            case 'int1':
            case 'bool':
                switch ($arg->type) {
                    case Variable::TYPE_NATIVE_BOOL:
                        return $value;
                    case Variable::TYPE_NATIVE_LONG:
                        return $context->builder->truncOrBitCast(
                            $value,
                            $context->getTypeFromString('int1')
                        );
                    case Variable::TYPE_VALUE:
                        return $context->builder->truncOrBitCast(
                            $context->builder->call(
                                $context->lookupFunction('__value__readLong'),
                                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $arg)
                            ),
                            $context->getTypeFromString('int1')
                        );
                }
                break;
            case 'double':
                switch ($arg->type) {
                    case Variable::TYPE_NATIVE_DOUBLE:
                        return $value;
                    case Variable::TYPE_NATIVE_LONG:
                        return $context->builder->siToFp(
                            $value,
                            $context->getTypeFromString('double')
                        );
                    case Variable::TYPE_NATIVE_BOOL:
                        return $context->builder->uiToFp(
                            $value,
                            $context->getTypeFromString('double')
                        );
                    case Variable::TYPE_VALUE:
                        return $context->builder->call(
                            $context->lookupFunction('__value__readDouble'),
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $arg)
                        );
                    case Variable::TYPE_OBJECT:
                        return $context->builder->siToFp(
                            $context->builder->ptrToInt(
                                $value,
                                $context->getTypeFromString('int64')
                            ),
                            $context->getTypeFromString('double')
                        );
                    case Variable::TYPE_NULL:
                        return $context->getTypeFromString('double')->constReal(0.0);
                }
                break;
        }
        throw new \LogicException("Unsupported cast for arg type $typeName from " . Variable::getStringType($arg->type));
    }

    /**
     * @param array<int, Variable> $args
     */
    private function enforceVariadicTrailingArgs(Context $context, array &$args): void
    {
        $idx = $this->variadicArgIndex;
        assert(null !== $idx);
        if (!$this->variadicSlotUsesElementTypeChecks($idx)) {
            return;
        }
        $extra = [];
        foreach ($args as $key => $arg) {
            if ((int) $key >= $idx) {
                $extra[(int) $key] = $arg;
            }
        }
        if (
            1 === \count($extra)
            && Variable::TYPE_HASHTABLE === reset($extra)->type
            && !empty(reset($extra)->variadicElementChecksDone)
        ) {
            return;
        }
        $strict = $context->callerStrictTypes;
        foreach ($extra as $key => $arg) {
            if (
                isset($this->paramTypeConstraintsByArg[$idx])
                && !$this->skipImplicitNullableTypeCheck($idx, $arg)
            ) {
                $constraint = $this->paramTypeConstraintsByArg[$idx];
                if ($strict) {
                    \PHPCompiler\JIT\TypeCheck::enforceParameter(
                        $context,
                        $arg,
                        $constraint,
                        true
                    );
                } else {
                    // Weak: coerce in place before pack so packed elements match Zend (#26587).
                    $coerced = \PHPCompiler\JIT\TypeCheck::coerceParameterWeak(
                        $context,
                        $arg,
                        $constraint
                    );
                    if (null === $coerced) {
                        \PHPCompiler\JIT\TypeCheck::enforceParameter(
                            $context,
                            $arg,
                            $constraint,
                            true
                        );
                    } else {
                        $args[$key] = $coerced;
                        $arg = $coerced;
                    }
                }
            }
            if (isset($this->paramIntersectionConstraintsByArg[$idx])) {
                \PHPCompiler\JIT\IntersectionParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramIntersectionConstraintsByArg[$idx]
                );
            }
            if (isset($this->paramDnfConstraintsByArg[$idx])) {
                $prefix = $this->receiverPrefix();
                $userIdx = $idx - $prefix;
                $paramName = $this->paramNames[$userIdx] ?? ('param'.$userIdx);
                \PHPCompiler\JIT\DnfParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramDnfConstraintsByArg[$idx],
                    'Argument',
                    $this->name,
                    $userIdx,
                    $paramName
                );
            }
            if (isset($this->paramClassConstraintsByArg[$idx])) {
                \PHPCompiler\JIT\ClassParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramClassConstraintsByArg[$idx]
                );
            }
        }
    }

    private function variadicSlotUsesElementTypeChecks(int $llvmArgIndex): bool
    {
        return isset($this->paramTypeConstraintsByArg[$llvmArgIndex])
            || isset($this->paramIntersectionConstraintsByArg[$llvmArgIndex])
            || isset($this->paramDnfConstraintsByArg[$llvmArgIndex])
            || isset($this->paramClassConstraintsByArg[$llvmArgIndex]);
    }

    private function skipImplicitNullableTypeCheck(int $index, Variable $arg): bool
    {
        return isset($this->paramImplicitNullableByArg[$index])
            && (Variable::TYPE_NULL === $arg->type || !empty($arg->isNullConstant));
    }

    /**
     * @param list<Variable> $args
     *
     * @return list<Variable>
     */
    private function packVariadicCallArgs(Context $context, array $args): array
    {
        $idx = $this->variadicArgIndex;
        assert(null !== $idx);
        $fixed = array_slice($args, 0, $idx);
        $extra = array_slice($args, $idx);
        if (1 === \count($extra) && Variable::TYPE_HASHTABLE === $extra[0]->type) {
            $packed = $extra[0];
        } elseif ([] === $extra) {
            $packed = HashTableHelper::emptyVariable($context);
        } else {
            $packed = HashTableHelper::packVariables($context, $extra);
        }

        return [...$fixed, $packed];
    }

    /**
     * Trailing by-ref variadic callers before pack (ZEND_SEND_REF … / #27407).
     *
     * @param array<int, Variable> $args
     *
     * @return list<Variable>
     */
    private function collectByRefVariadicCallerArgs(array $args): array
    {
        $start = $this->variadicArgIndex;
        assert(null !== $start);
        $end = \count($args) - 1;
        if (null !== $this->namedArgsVariadicIndex) {
            $trailing = \count($this->paramNames) - $this->namedArgsVariadicIndex - 1;
            if ($trailing > 0) {
                $end = \count($args) - $trailing - 1;
            }
        }
        $out = [];
        for ($idx = $start; $idx <= $end; ++$idx) {
            if (isset($args[$idx])) {
                $out[] = $args[$idx];
            }
        }

        return $out;
    }

    /**
     * Copy packed HT slots back into by-ref caller lvalues after the callee returns (#27407).
     *
     * @param list<Variable> $callers
     */
    private function syncByRefVariadicCallers(
        Context $context,
        Variable $packedHt,
        array $callers
    ): void {
        if ([] === $callers) {
            return;
        }
        $ht = $context->helper->loadValue($packedHt);
        $i64 = $context->getTypeFromString('int64');
        foreach ($callers as $i => $caller) {
            $boxed = HashTableHelper::readIndexedToValueBox(
                $context,
                $ht,
                $i64->constInt((int) $i, false)
            );
            JitValueBox::assignToPointer(
                $context,
                JitValueBox::valuePtrFromVariable($context, $caller),
                $boxed
            );
        }
    }

    private function missingCallArg(Context $context, \PHPLLVM\Type $llvmType): Variable
    {
        $typeName = $context->getStringFromType($llvmType);
        switch ($typeName) {
            case '__object__*':
                return new Variable(
                    $context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $context->getTypeFromString('__object__*')->constNull()
                );
            case 'int64':
            case 'long long':
                return new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt(0, false)
                );
            default:
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);

                return new Variable(
                    $context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
        }
    }

}
