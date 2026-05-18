<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * gettype() for scalar values supported by this compiler (subset of PHP).
 */
final class gettype extends Internal
{
    private const VM_NAMES = [
        Variable::TYPE_NULL => 'NULL',
        Variable::TYPE_INTEGER => 'integer',
        Variable::TYPE_FLOAT => 'double',
        Variable::TYPE_BOOLEAN => 'boolean',
        Variable::TYPE_STRING => 'string',
        Variable::TYPE_ARRAY => 'array',
    ];

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('gettype() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (!isset(self::VM_NAMES[$v->type])) {
            throw new \LogicException('gettype() does not support this value type in this compiler build');
        }
        $frame->returnVar->string(self::VM_NAMES[$v->type]);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('gettype() requires exactly one argument');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $label = 'integer';
                break;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $label = 'double';
                break;
            case JITVariable::TYPE_NATIVE_BOOL:
                $label = 'boolean';
                break;
            case JITVariable::TYPE_STRING:
                $label = 'string';
                break;
            case JITVariable::TYPE_NULL:
                $label = 'NULL';
                break;
            default:
                throw new \LogicException('gettype() does not support this value type in this compiler build');
        }

        return $context->builder->load($context->constantStringFromString($label));
    }
}
