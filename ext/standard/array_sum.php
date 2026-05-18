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
 * array_sum() for arrays of integers and floats (subset of PHP; VM only).
 */
final class array_sum extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_sum() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_sum() argument must be an array in this compiler build');
        }
        $sumInt = 0;
        $sumFloat = 0.0;
        $useFloat = false;
        foreach ($array->toArray()->iterate(true) as $value) {
            $v = $value->resolveIndirect();
            if (Variable::TYPE_INTEGER === $v->type) {
                if ($useFloat) {
                    $sumFloat += (float) $v->toInt();
                } else {
                    $sumInt += $v->toInt();
                }
                continue;
            }
            if (Variable::TYPE_FLOAT === $v->type) {
                if (!$useFloat) {
                    $useFloat = true;
                    $sumFloat = (float) $sumInt + $v->toFloat();
                } else {
                    $sumFloat += $v->toFloat();
                }
                continue;
            }
            throw new \LogicException('array_sum() only supports integer and float elements in this compiler build');
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
        throw new \LogicException('array_sum() is not implemented for JIT in this compiler build');
    }
}
