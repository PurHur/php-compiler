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
 * sprintf() — %s %d %f %b %x %X %o %u %c %e %E %g %G %a %A %%, %n$ positional (LLVM JIT/AOT via __compiler_sprintf, #4156, #3631).
 */
final class sprintf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('sprintf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('sprintf() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $fmtVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $fmtVar->type) {
            throw new \LogicException('sprintf() format must be a string in this compiler build');
        }
        $values = [];
        for ($i = 1; $i < $argc; ++$i) {
            $values[] = $frame->calledArgs[$i]->resolveIndirect();
        }
        $frame->returnVar->string(VmSprintf::format($fmtVar->toString(), $values, $frame));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSprintf::formatWithFmt(
            $context,
            $this->jitString($context, $args[0], 'sprintf() format'),
            ...\array_slice($args, 1)
        );
    }
}
