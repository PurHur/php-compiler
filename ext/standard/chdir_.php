<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** chdir() — VM via VmChdirNative (libc); JIT/AOT via JitChdir (#8180). */
final class chdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chdir');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('chdir() requires exactly one argument in this compiler build');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'chdir', 0, 'directory', $frame, true);
        $ok = VmFs::chdir($path);
        if (!$ok) {
            VmFilestatFailure::warnChdirFailed($frame, $path);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('chdir() requires exactly one argument in this compiler build');
        }
        return JitChdir::invoke(
            $context,
            JitFilestatArg::lowerFilename($context, $args[0], 'chdir', 0, 'directory', true)
        );
    }
}
