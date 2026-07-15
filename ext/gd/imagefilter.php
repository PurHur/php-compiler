<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagefilter() — raster filter dispatch (php-src ext/gd/gd.c; #6380). */
final class imagefilter extends Internal
{
    public function __construct()
    {
        parent::__construct('imagefilter');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 6) {
            throw new \LogicException('imagefilter() expects 2 to 6 arguments in this compiler build');
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagefilter', 1);
        $filter = VmGd::coerceIntArg($frame->calledArgs[1], 'imagefilter', 2, 'filtertype');
        $arg1 = $argc >= 3 ? VmGd::coerceIntArg($frame->calledArgs[2], 'imagefilter', 3, 'arg1') : 0;
        $arg2 = $argc >= 4 ? VmGd::coerceIntArg($frame->calledArgs[3], 'imagefilter', 4, 'arg2') : 0;
        $arg3 = $argc >= 5 ? VmGd::coerceIntArg($frame->calledArgs[4], 'imagefilter', 5, 'arg3') : 0;
        $arg4 = $argc >= 6 ? VmGd::coerceIntArg($frame->calledArgs[5], 'imagefilter', 6, 'arg4') : 0;
        $frame->returnVar->bool(VmGd::applyFilter($frame, $image, $filter, $arg1, $arg2, $arg3, $arg4));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagefilter() is VM-only in this compiler build (#6380)');
    }
}
