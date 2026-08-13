<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** symlink() — VM via VmFs; JIT/AOT via SymlinkJitHelper PHP (#15544). */
final class symlink_ extends Internal
{
    public function __construct()
    {
        parent::__construct('symlink');
    }

    public function execute(Frame $frame): void
    {
        // php-src link.c / basic_functions.stub.php — exactly 2 (#30553).
        $this->requireExactArgCount($frame, 'symlink', 2);
        $target = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'symlink', 0, 'target', $frame);
        $linkPath = VmFilestatArg::coerceFilenameArg($frame->calledArgs[1], 'symlink', 1, 'link', $frame);
        $ok = VmFs::symlink($target, $linkPath);
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'symlink');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30553 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'symlink', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $target = JitStringBuiltinArg::lowerPath($context, $args[0], 'symlink', 0, 'target');
        $linkPath = JitStringBuiltinArg::lowerPath($context, $args[1], 'symlink', 1, 'link');

        return JitSymlink::invoke($context, $target, $linkPath);
    }
}
