<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** link() — VM via VmFs; JIT/AOT via libc linkat(2) (issue #3589). */
final class link_ extends Internal
{
    public function __construct()
    {
        parent::__construct('link');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('link() requires exactly two arguments in this compiler build');
        }
        $targetVar = $frame->calledArgs[0]->resolveIndirect();
        $linkVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $targetVar->type || Variable::TYPE_STRING !== $linkVar->type) {
            throw new \LogicException('link() requires string paths in this compiler build');
        }
        $target = $targetVar->toString();
        $linkPath = $linkVar->toString();
        $frame->returnVar->bool(VmFs::hardLink($target, $linkPath));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('link() requires exactly two arguments in this compiler build');
        }
        $target = $this->jitString($context, $args[0], 'link() argument #1');
        $linkPath = $this->jitString($context, $args[1], 'link() argument #2');

        return JitLink::invoke($context, $target, $linkPath);
    }
}
