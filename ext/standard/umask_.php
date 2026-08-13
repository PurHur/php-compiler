<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** umask() — process file-creation mask (VM host; JIT/AOT via UmaskJitHelper PHP, #3226, #15497). */
final class umask_ extends Internal
{
    public function __construct()
    {
        parent::__construct('umask');
    }

    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — at most 1 (#30554).
        $this->requireAtMostArgCount($frame, 'umask', 1);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->int((int) \umask());

            return;
        }
        $mask = VmMath::parseNullableIntBuiltinArgForFrame(
            $frame,
            0,
            'umask',
            1,
            'mask'
        );
        $frame->returnVar->int((int) \umask($mask));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireAtMostJitArgCount($context, $args, 'umask', 1)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $mask = null;
        if (isset($args[0])) {
            $mask = JitSleep::zParamNullableLong($context, $args[0], 'umask', 1, 'mask');
        }

        return JitUmask::invoke($context, $mask);
    }
}
