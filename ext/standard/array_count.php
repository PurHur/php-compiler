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
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * count() for arrays (subset of PHP; php-src ext/standard/array.c).
 */
final class array_count extends Internal
{
    public function __construct(string $name = 'count')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('count() expects at least 1 argument, at most 2');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('count() requires VM context in this compiler build');
        }
        $mode = VmArray::COUNT_NORMAL;
        if (2 === $argc) {
            $modeArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeArg->type) {
                throw new \TypeError('count(): Argument #2 ($mode) must be of type int');
            }
            $mode = $modeArg->toInt();
            if (VmArray::COUNT_NORMAL !== $mode && VmArray::COUNT_RECURSIVE !== $mode) {
                throw new \LogicException(
                    'count(): Parameter must be an integer or use the COUNT_RECURSIVE flag'
                );
            }
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $ht = $v->toArray();
            if (VmArray::COUNT_RECURSIVE === $mode) {
                $frame->returnVar->int(VmArray::countRecursive($ht));
            } else {
                $frame->returnVar->int($ht->getNumElements());
            }
        } else {
            $frame->returnVar->int(VmArray::countValue($frame->vmContext, $v));
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('count() expects at least 1 argument, at most 2');
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_count() argument #'.((int) $i + 1));
            }
        }
        if (2 === $argc) {
            $modeLit = JitLongArg::compileTimeLiteral($args[1]);
            if (null === $modeLit) {
                throw new \LogicException('count() mode must be a compile-time integer in this compiler build');
            }
            if (VmArray::COUNT_RECURSIVE === $modeLit) {
                throw new \LogicException('count() COUNT_RECURSIVE is not supported in JIT in this compiler build');
            }
            if (VmArray::COUNT_NORMAL !== $modeLit) {
                throw new \LogicException(
                    'count(): Parameter must be an integer or use the COUNT_RECURSIVE flag'
                );
            }
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromInteger($args[0]->nextFreeElement, 'int64');
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            return ArrayBuiltinHelper::getNumElements(
                $context,
                ArrayBuiltinHelper::loadHashTable($context, $args[0])
            );
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type || JitValueBox::isValueOperand($args[0])) {
            $ht = ArrayBuiltinHelper::loadHashTable($context, $args[0]);

            return ArrayBuiltinHelper::getNumElements($context, $ht);
        }
        throw new \LogicException('count() only supports native arrays in this compiler build');
    }
}
