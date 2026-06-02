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

    /** LLVM arg index => by-reference formal (issue #3161, #140). @var array<int, true> */
    public array $paramByRefByArg = [];

    /** Declared parameter names by index (issue #3777). */
    public array $paramNames = [];

    /** PHP variadic parameter index for named-arg resolution (issue #3777). */
    public ?int $namedArgsVariadicIndex = null;

    public function __construct(
        Value $function,
        string $name,
        array $argTypes,
        array $defaultArgs = [],
        ?int $variadicArgIndex = null,
        array $paramTypeConstraintsByArg = [],
        array $paramIntersectionConstraintsByArg = [],
        array $paramDnfConstraintsByArg = [],
        array $paramByRefByArg = [],
        array $paramNames = [],
        ?int $namedArgsVariadicIndex = null
    ) {
        $this->function = $function;
        $this->name = $name;
        $this->argTypes = $argTypes;
        $this->defaultArgs = $defaultArgs;
        $this->variadicArgIndex = $variadicArgIndex;
        $this->paramTypeConstraintsByArg = $paramTypeConstraintsByArg;
        $this->paramIntersectionConstraintsByArg = $paramIntersectionConstraintsByArg;
        $this->paramDnfConstraintsByArg = $paramDnfConstraintsByArg;
        $this->paramByRefByArg = $paramByRefByArg;
        $this->paramNames = $paramNames;
        $this->namedArgsVariadicIndex = $namedArgsVariadicIndex;
    }

    public function call(Context $context, Variable ... $args): Value {
        $sentArgs = $args;
        if (null !== $this->variadicArgIndex) {
            $this->enforceVariadicTrailingArgs($context, $args);
            $args = $this->packVariadicCallArgs($context, $args);
        }
        // Store call-site argv for func_get_args/func_num_args (issue #197).
        CallArgv::emitStore($context, HashTableHelper::packVariables($context, $sentArgs));
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
            if (!$skipVariadicPackedTypeCheck && isset($this->paramTypeConstraintsByArg[$index])) {
                \PHPCompiler\JIT\TypeCheck::enforceParameter(
                    $context,
                    $arg,
                    $this->paramTypeConstraintsByArg[$index],
                    $context->callerStrictTypes
                );
            }
            if (!$skipVariadicPackedTypeCheck && isset($this->paramIntersectionConstraintsByArg[$index])) {
                \PHPCompiler\JIT\IntersectionParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramIntersectionConstraintsByArg[$index]
                );
            }
            if (!$skipVariadicPackedTypeCheck && isset($this->paramDnfConstraintsByArg[$index])) {
                \PHPCompiler\JIT\DnfParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramDnfConstraintsByArg[$index]
                );
            }
            $argValues[] = $this->compileArg($context, $arg, $index);
        }
        return $context->builder->call(
            $this->function,
            ...$argValues
        );
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
                            Variable::KIND_VARIABLE === $arg->kind
                                ? \PHPCompiler\JIT\JitValueBox::pointer($context, $arg->value)
                                : $value
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
                        if ('__value__*' === $context->getStringFromType($value->typeOf())) {
                            return $value;
                        }

                        return $context->builder->load($value);
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
                        $long = $context->builder->zExt(
                            $value,
                            $context->getTypeFromString('int64')
                        );
                        $context->builder->call(
                            $context->lookupFunction('__value__writeLong'),
                            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot),
                            $long
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
                        return $context->builder->fpToSi(
                            $value,
                            $context->getTypeFromString('int64')
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
     * @param list<Variable> $args
     */
    private function enforceVariadicTrailingArgs(Context $context, array $args): void
    {
        $idx = $this->variadicArgIndex;
        assert(null !== $idx);
        if (!$this->variadicSlotUsesElementTypeChecks($idx)) {
            return;
        }
        $extra = array_slice($args, $idx);
        $strict = $context->callerStrictTypes;
        foreach ($extra as $arg) {
            if (isset($this->paramTypeConstraintsByArg[$idx])) {
                \PHPCompiler\JIT\TypeCheck::enforceParameter(
                    $context,
                    $arg,
                    $this->paramTypeConstraintsByArg[$idx],
                    $strict
                );
            }
            if (isset($this->paramIntersectionConstraintsByArg[$idx])) {
                \PHPCompiler\JIT\IntersectionParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramIntersectionConstraintsByArg[$idx]
                );
            }
            if (isset($this->paramDnfConstraintsByArg[$idx])) {
                \PHPCompiler\JIT\DnfParamCheck::enforce(
                    $context,
                    $arg,
                    $this->paramDnfConstraintsByArg[$idx]
                );
            }
        }
    }

    private function variadicSlotUsesElementTypeChecks(int $llvmArgIndex): bool
    {
        return isset($this->paramTypeConstraintsByArg[$llvmArgIndex])
            || isset($this->paramIntersectionConstraintsByArg[$llvmArgIndex])
            || isset($this->paramDnfConstraintsByArg[$llvmArgIndex]);
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
