<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** symlink() — VM via VmFs; JIT/AOT via libc symlinkat(2) (issue #3227). */
final class symlink_ extends Internal
{
    public function __construct()
    {
        parent::__construct('symlink');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('symlink() requires exactly two arguments in this compiler build');
        }
        $targetVar = $frame->calledArgs[0]->resolveIndirect();
        $linkVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $targetVar->type || Variable::TYPE_STRING !== $linkVar->type) {
            throw new \LogicException('symlink() requires string paths in this compiler build');
        }
        $ok = VmFs::symlink($targetVar->toString(), $linkVar->toString());
        if (!$ok) {
            VmFilestatFailure::warnNoSuchFile($frame, 'symlink');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('symlink() requires exactly two arguments in this compiler build');
        }
        $target = $this->jitString($context, $args[0], 'symlink() argument #1');
        $linkPath = $this->jitString($context, $args[1], 'symlink() argument #2');

        return JitSymlink::invoke($context, $target, $linkPath);
    }
}
