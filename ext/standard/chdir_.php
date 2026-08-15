<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * chdir() — VM via VmChdirNative; JIT/AOT via ChdirJitHelper (#8180, #21147).
 * Excess/missing argc → Zend ArgumentCountError (#30585).
 */
final class chdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chdir');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30585; ext/standard/dir.c).
        $this->requireExactArgCount($frame, 'chdir', 1);
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'chdir', 0, 'directory', $frame);
        $ok = VmFs::chdir($path);
        if (!$ok) {
            VmFilestatFailure::warnChdirFailed($frame, $path);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30585).
        if (!$this->requireExactJitArgCount($context, $args, 'chdir', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        return JitChdir::invoke(
            $context,
            JitFilestatArg::lowerFilename($context, $args[0], 'chdir', 0, 'directory')
        );
    }
}
