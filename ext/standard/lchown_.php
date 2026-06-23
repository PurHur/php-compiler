<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** lchown() — VM via VmFs; JIT/AOT via __compiler_chown (php-src ext/standard/filestat.c). */
final class lchown_ extends Internal
{
    public function __construct()
    {
        parent::__construct('lchown');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('lchown() requires exactly two arguments in this compiler build');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'lchown');
        $userVar = VmFilestatArg::requireIntOrStringArg($frame->calledArgs[1], 'lchown', 1, 'user');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::lchown($path, $userVar);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'chown');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('lchown() requires exactly two arguments in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'lchown');
        $userPtr = JitFilestatArg::valuePtrAfterIntOrStringGuard($context, $args[1], 'lchown', 1, 'user');

        return JitChown::invoke($context, $path, $userPtr, true);
    }
}
