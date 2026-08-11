<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** lchgrp() — VM via VmFs; JIT/AOT via __compiler_chgrp (php-src ext/standard/filestat.c). */
final class lchgrp_ extends Internal
{
    public function __construct()
    {
        parent::__construct('lchgrp');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('lchgrp() requires exactly two arguments in this compiler build');
        }
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'lchgrp');
        $groupVar = VmFilestatArg::requireIntOrStringArgForFrame($frame, 1, 'lchgrp', 'group');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::lchgrp($path, $groupVar);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'lchgrp');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('lchgrp() requires exactly two arguments in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'lchgrp');
        $groupPtr = JitFilestatArg::valuePtrAfterIntOrStringGuard($context, $args[1], 'lchgrp', 1, 'group');

        return JitChgrp::invoke($context, $path, $groupPtr, true);
    }
}
