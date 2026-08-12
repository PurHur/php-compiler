<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** chroot() — VM via VmChrootNative; JIT/AOT via ChrootJitHelper (#3500, #30558). */
final class chroot_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chroot');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('chroot() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'chroot', 0, 'directory');
        $ok = VmChrootNative::chroot($path);
        if (!$ok) {
            // php-src dir.c — emit Zend-shaped warning; host @\chroot swallows diagnostics (#29360).
            VmFilestatFailure::warnChrootFailed($frame);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('chroot() requires exactly one argument in this compiler build');
        }

        return JitChroot::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'chroot', 0, 'directory')
        );
    }
}
