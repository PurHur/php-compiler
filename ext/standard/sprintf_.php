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
 * sprintf() — %s %d %f %b %x %X %o %u %c %e %E %g %G %%, %n$ positional (LLVM JIT/AOT via __compiler_sprintf, #4156, #3631).
 * %a/%A → ValueError like Zend (#29085; retract #9059 phantom).
 */
final class sprintf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('sprintf');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'sprintf', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $format = VmString::stringBuiltinArgForFrame($frame, 0, 'sprintf', 0, 'format');
        $argc = \count($frame->calledArgs);
        $values = [];
        for ($i = 1; $i < $argc; ++$i) {
            $values[] = $frame->calledArgs[$i]->resolveIndirect();
        }
        $frame->returnVar->string(VmSprintf::format($format, $values, $frame));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireAtLeastJitArgCount($context, $args, 'sprintf', 1)) {
            return $context->constantFromString('');
        }

        return JitSprintf::format($context, ...$args);
    }
}
