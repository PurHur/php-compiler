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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * sort() for homogeneous packed string or integer arrays (subset of PHP).
 *
 * VM: full support. JIT/AOT: dynamic hashtable arrays only (not fixed native literals).
 */
final class sort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('sort');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('sort() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('sort() argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $values[] = $copy;
        }
        \usort($values, static function (Variable $a, Variable $b): int {
            $a = $a->resolveIndirect();
            $b = $b->resolveIndirect();
            if (Variable::TYPE_STRING === $a->type && Variable::TYPE_STRING === $b->type) {
                return VmString::strcmp($a->toString(), $b->toString());
            }
            if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_INTEGER === $b->type) {
                return $a->toInt() <=> $b->toInt();
            }

            throw new \LogicException(
                'sort() only supports homogeneous string or integer arrays in this compiler build'
            );
        });
        $ht->replacePackedValues($values);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('sort() requires exactly one argument');
        }
        ArrayBuiltinHelper::sortPacked($context, $args[0]);

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'sort() argument #'.((int) $i + 1));
            }
        }
        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
