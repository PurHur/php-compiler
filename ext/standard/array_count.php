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
use PHPCompiler\JIT\Builtin\ArrayCountRecursiveRuntime;
use PHPCompiler\JIT\Builtin\ArrayCountRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
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
            $result = VmArray::COUNT_RECURSIVE === $mode
                ? VmArray::countRecursive($ht, $frame)
                : $ht->getNumElements();
        } else {
            $result = VmArray::countValue($frame->vmContext, $v);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($result);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        TypeErrorRaise::ensureLinked($context);
        if ($argc < 1 || $argc > 2) {
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'count() expects at least 1 argument, '.$argc.' given'
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $recursive = false;
        if (2 === $argc) {
            $modeLit = JitLongArg::compileTimeLiteral($args[1]);
            if (null === $modeLit) {
                throw new \LogicException('count() mode must be a compile-time integer in this compiler build');
            }
            if (VmArray::COUNT_RECURSIVE === $modeLit) {
                $recursive = true;
            } elseif (VmArray::COUNT_NORMAL !== $modeLit) {
                throw new \LogicException(
                    'count(): Parameter must be an integer or use the COUNT_RECURSIVE flag'
                );
            }
        }
        if ($recursive) {
            if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
                || JITVariable::TYPE_HASHTABLE === $args[0]->type
                || JITVariable::TYPE_VALUE === $args[0]->type
                || JitValueBox::isValueOperand($args[0])
            ) {
                return ArrayCountRecursiveRuntime::countRecursive($context, $args[0]);
            }
            $this->emitCountTypeError($context, $args[0]);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromInteger($args[0]->nextFreeElement, 'int64');
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            return ArrayCountRuntime::numElements($context, $args[0]);
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type || JitValueBox::isValueOperand($args[0])) {
            return ArrayCountRuntime::numElements($context, $args[0]);
        }
        $this->emitCountTypeError($context, $args[0]);

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private function emitCountTypeError(Context $context, JITVariable $arg): void
    {
        TypeErrorRaise::emitRaise(
            $context,
            'count(): Argument #1 ($value) must be of type Countable|array, '
            .$this->jitArgTypeLabel($arg).' given'
        );
    }

    private function jitArgTypeLabel(JITVariable $arg): string
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_OBJECT:
                return $this->jitCompileTimeObjectLabel($arg);
            default:
                return 'mixed';
        }
    }

    private function jitCompileTimeObjectLabel(JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $this->context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $classIdVal = $this->context->builder->load(
            $this->context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }

        return $this->context->type->object->classNameForId((int) $classIdVal->getConstantValue());
    }
}
