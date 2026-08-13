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
        // php-src filestat.c / basic_functions.stub.php — exactly 2 (#30554).
        $this->requireExactArgCount($frame, 'chown', 2);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'chown');
        $userVar = VmFilestatArg::requireIntOrStringArgForFrame($frame, 1, 'chown', 'user');
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
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'chown', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'chown');
        $userPtr = JitFilestatArg::valuePtrAfterIntOrStringGuard($context, $args[1], 'chown', 1, 'user');

        return JitChown::invoke($context, $path, $userPtr, false);
    }
}
