<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chgrp() — VM via VmFs; JIT/AOT via __compiler_chgrp (php-src ext/standard/filestat.c). */
final class chgrp_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chgrp');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('chgrp() requires exactly two arguments in this compiler build');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'chgrp');
        $groupVar = VmFilestatArg::requireIntOrStringArg($frame->calledArgs[1], 'chgrp', 1, 'group');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::chgrp($path, $groupVar);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'chgrp');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('chgrp() requires exactly two arguments in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'chgrp');
        $groupPtr = JitFilestatArg::valuePtrAfterIntOrStringGuard($context, $args[1], 'chgrp', 1, 'group');

        return JitChgrp::invoke($context, $path, $groupPtr, false);
    }
}
