<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chown() — VM via VmFs; JIT/AOT via __compiler_chown (php-src ext/standard/filestat.c). */
final class chown_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chown');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('chown() requires exactly two arguments in this compiler build');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'chown');
        $userVar = VmFilestatArg::requireIntOrStringArg($frame->calledArgs[1], 'chown', 1, 'user');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::chown($path, $userVar);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'chown');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('chown() requires exactly two arguments in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'chown');
        $userPtr = JitFilestatArg::valuePtrAfterIntOrStringGuard($context, $args[1], 'chown', 1, 'user');

        return JitChown::invoke($context, $path, $userPtr, false);
    }
}
