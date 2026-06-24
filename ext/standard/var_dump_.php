<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * var_dump() — debug export with __debugInfo hook (issues #3133, #3259).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/var.c php_var_dump
 */
final class var_dump_ extends Internal
{
    public function __construct()
    {
        parent::__construct('var_dump');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('var_dump() requires VM context');
        }
        $vm = $frame->vmContext->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('var_dump() requires an active VM');
        }
        foreach ($frame->calledArgs as $arg) {
            VmVarDump::dumpVariable($vm, $arg->resolveIndirect(), 1, false, $frame);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitVarDump::invoke($context, ...$args);
    }
}
