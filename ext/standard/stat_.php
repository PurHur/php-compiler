<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stat() — file metadata array via VmStatCache / libc stat(2) (issue #1197, #7844). */
final class stat_ extends Internal
{
    public function __construct()
    {
        parent::__construct('stat');
    }

    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — exactly 1 (#30554).
        $this->requireExactArgCount($frame, 'stat', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'stat');
        if (null === $frame->returnVar) {
            return;
        }
        $info = VmFs::statInfo($path, false);
        if (false === $info) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'stat', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'stat', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'stat');

        return JitStatArray::invoke($context, $path, false);
    }
}
