<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * spl_autoload_functions() — list registered autoload callbacks (ext/spl/php_spl.c, #4256).
 */
final class spl_autoload_functions extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload_functions');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'spl_autoload_functions() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $labels = VmSplAutoload::registeredFunctions($ctx);
        if ([] === $labels) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        $index = 0;
        foreach ($labels as $label) {
            $key = new Variable();
            $key->int($index++);
            $value = new Variable();
            if (\is_string($label)) {
                $value->string($label);
            } else {
                $elem0 = new Variable();
                $elem0->string($label[0]);
                $elem1 = new Variable();
                $elem1->string($label[1]);
                $inner = new HashTable();
                $k0 = new Variable();
                $k0->int(0);
                $k1 = new Variable();
                $k1->int(1);
                array_map::appendKeyedCopy($inner, $k0, $elem0);
                array_map::appendKeyedCopy($inner, $k1, $elem1);
                $value->array($inner);
            }
            array_map::appendKeyedCopy($ht, $key, $value);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'spl_autoload_functions() is not implemented for JIT in this compiler build (#4256)'
        );
    }
}
