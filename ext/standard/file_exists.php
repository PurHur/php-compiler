<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** file_exists() — VM via VmStatPath; JIT via StatPathRuntime PHP bridge (issue #194, #8186, #9112). */
final class file_exists extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30544).
        $this->requireExactArgCount($frame, 'file_exists', 1);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'file_exists');
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmOpenBasedir::check($path, true, 'file_exists', $frame->vmContext, $frame)) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmStatPath::exists($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30544 / peer #30523).
        if (!$this->requireExactJitArgCount($context, $args, 'file_exists', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'file_exists');

        return JitStat::pathExists($context, $path);
    }
}
