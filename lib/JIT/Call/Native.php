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
use PHPCompiler\JIT\Variable;

use PHPLLVM\Value;

class Native implements Call {

    public Value $function;
    public string $name;
    public array $argTypes;

    /** @var array<int, Variable> compile-time defaults for optional parameters */
    public array $defaultArgs = [];

    public function __construct(Value $function, string $name, array $argTypes, array $defaultArgs = []) {
        $this->function = $function;
        $this->name = $name;
        $this->argTypes = $argTypes;
        $this->defaultArgs = $defaultArgs;
    }

    public function call(Context $context, Variable ... $args): Value {
        $argValues = [];
        $total = count($this->argTypes);
        for ($index = 0; $index < $total; $index++) {
            if (isset($args[$index])) {
                $arg = $args[$index];
            } elseif (isset($this->defaultArgs[$index])) {
                $arg = $this->defaultArgs[$index];
            } else {
                throw new \LogicException("Missing required argument {$index} for {$this->name}()");
            }
            $argValues[] = $this->compileArg($context, $arg, $index);
        }
        return $context->builder->call(
            $this->function,
            ...$argValues
        );
    }

    protected function compileArg(Context $context, Variable $arg, int $argNum): Value {
        $type = $this->argTypes[$argNum];
        $typeName = $context->getStringFromType($type);
        $value = $context->helper->loadValue($arg);
        switch ($typeName) {
            case '__object__*':
                switch ($arg->type) {
                    case Variable::TYPE_OBJECT:
                        return $value;
                }
                break;
            case '__hashtable__*':
                switch ($arg->type) {
                    case Variable::TYPE_HASHTABLE:
                        return $value;
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
                switch ($arg->type) {
                    case Variable::TYPE_STRING:
                        return $value;
                    case Variable::TYPE_VALUE:
                        return $context->builder->call(
                            $context->lookupFunction('__value__readString'),
                            $value
                        );
                }
                break;
            case '__value__':
                switch ($arg->type) {
                    case Variable::TYPE_VALUE:
                        return $value;
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
                }
                break;
            case 'int64':
                switch ($arg->type) {
                    case Variable::TYPE_NATIVE_LONG:
                        return $value;
                    case Variable::TYPE_VALUE:
                        return $context->builder->call(
                            $context->lookupFunction('__value__readLong'),
                            $value
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
                    case Variable::TYPE_VALUE:
                        return $context->builder->call(
                            $context->lookupFunction('__value__readDouble'),
                            $value
                        );
                }
                break;
        }
        throw new \LogicException("Unsupported cast for arg type $typeName from " . Variable::getStringType($arg->type));
    }

}
