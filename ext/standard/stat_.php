<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** stat() — file metadata array via host stat(2) (issue #1197). */
final class stat_ extends Internal
{
    public function __construct()
    {
        parent::__construct('stat');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stat() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('stat() requires a string path in this compiler build');
        }
        $info = VmFs::statInfo($v->toString(), false);
        if (false === $info) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($info);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stat() requires exactly one argument in this compiler build');
        }
        $path = JitStringArg::lower($context, $args[0], 'stat() path');

        return JitStatArray::invoke($context, $path, false);
    }
}
