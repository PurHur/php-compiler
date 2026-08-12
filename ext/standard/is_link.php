<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** is_link() — VM via VmStatPath; JIT/AOT via libc lstat(2) S_IFLNK (#8186). */
final class is_link extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30544).
        $this->requireExactArgCount($frame, 'is_link', 1);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'is_link');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmStatPath::isLink($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30544 / peer #30523).
        if (!$this->requireExactJitArgCount($context, $args, 'is_link', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'is_link', 0, 'filename');

        return JitStat::pathIsLink($context, $path);
    }
}
