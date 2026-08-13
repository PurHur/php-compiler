<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** dir() — open directory as Directory object (php-src ext/standard/dir.c; #13368). */
final class dir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('dir');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('dir() requires exactly one argument in this compiler build');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'dir', 0, 'directory', $frame);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('dir() requires VM context in this compiler build');
        }

        $handle = VmDir::opendir($path);
        if (false === $handle) {
            if ('' !== $path) {
                VmFilestatFailure::warnPathOpenDirFailed($frame, 'dir', $path);
            }
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom(DirectoryBuiltin::fromOpendir($frame->vmContext, $path, $handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('dir() requires exactly one argument in this compiler build');
        }

        return JitDir::invoke(
            $context,
            JitFilestatArg::lowerFilename($context, $args[0], 'dir', 0, 'directory')
        );
    }
}
