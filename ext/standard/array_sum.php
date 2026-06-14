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
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_sum() for arrays of integers and floats (subset of PHP; native LLVM in JIT).
 */
final class array_sum extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_sum() requires exactly one argument');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_sum');
        if (null === $frame->returnVar) {
            return;
        }
        $sumInt = 0;
        $sumFloat = 0.0;
        $useFloat = false;
        foreach ($ht->iterate(true) as $value) {
            $coerced = VmArray::coerceArrayFoldNumericElement($value);
            if (null === $coerced) {
                continue;
            }
            [$num, $isFloat] = $coerced;
            if ($isFloat) {
                if (!$useFloat) {
                    $useFloat = true;
                    $sumFloat = (float) $sumInt + (float) $num;
                } else {
                    $sumFloat += (float) $num;
                }
                continue;
            }
            if ($useFloat) {
                $sumFloat += (float) $num;
            } else {
                $sumInt += (int) $num;
            }
        }
        if ($useFloat) {
            $frame->returnVar->float($sumFloat);
        } else {
            $frame->returnVar->int($sumInt);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        TypeErrorRaise::ensureLinked($context);
        if (1 !== \count($args)) {
            throw new \LogicException('array_sum() requires exactly one argument');
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_sum() argument #'.((int) $i + 1));
            }
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return ArrayBuiltinHelper::arraySum($context, $args[0]);
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            return ArrayBuiltinHelper::arraySum($context, $args[0]);
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            JitArrayElem::requireArrayArg($context, $args[0], 'array_sum');

            return ArrayBuiltinHelper::arraySum($context, $args[0]);
        }
        TypeErrorRaise::emitRaise(
            $context,
            'array_sum(): Argument #1 ($array) must be of type array, '
            .$this->jitArgTypeLabel($args[0]).' given'
        );

        return $context->getTypeFromString('int64')->constInt(0, false);
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
                return 'object';
            default:
                return 'mixed';
        }
    }
}
