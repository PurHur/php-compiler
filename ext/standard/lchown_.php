<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * lchown() — VM via VmFs; JIT/AOT via __compiler_chown (php-src ext/standard/filestat.c).
 * Excess/missing argc → Zend ArgumentCountError (#30568).
 */
final class lchown_ extends Internal
{
    public function __construct()
    {
        parent::__construct('lchown');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 2 (#30568; ext/standard/filestat.c).
        $this->requireExactArgCount($frame, 'lchown', 2);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'lchown');
        $userVar = VmFilestatArg::requireIntOrStringArgForFrame($frame, 1, 'lchown', 'user');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmFs::lchown($path, $userVar);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'lchown');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30568).
        if (!$this->requireExactJitArgCount($context, $args, 'lchown', 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'lchown');
        $userPtr = JitFilestatArg::valuePtrAfterIntOrStringGuard($context, $args[1], 'lchown', 1, 'user');

        return JitChown::invoke($context, $path, $userPtr, true);
    }
}
