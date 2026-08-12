<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** is_file() — VM via VmStatPath; JIT via libc stat (issue #194, #8186). */
final class is_file extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30544).
        $this->requireExactArgCount($frame, 'is_file', 1);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'is_file');
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmOpenBasedir::check($path, true, 'is_file', $frame->vmContext, $frame)) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmStatPath::isFile($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30544 / peer #30523).
        if (!$this->requireExactJitArgCount($context, $args, 'is_file', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'is_file', 0, 'filename');

        return JitStat::pathIsFile($context, $path);
    }
}
