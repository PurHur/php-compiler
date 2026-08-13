<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** lstat() — symlink-aware metadata via VmStatCache / libc lstat(2) (issue #1198, #7844). */
final class lstat_ extends Internal
{
    public function __construct()
    {
        parent::__construct('lstat');
    }

    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — exactly 1 (#30554).
        $this->requireExactArgCount($frame, 'lstat', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'lstat');
        if (null === $frame->returnVar) {
            return;
        }
        $info = VmFs::statInfo($path, true);
        if (false === $info) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'lstat', $path, true);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'lstat', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'lstat');

        return JitStatArray::invoke($context, $path, true);
    }
}
