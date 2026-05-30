<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** php_uname() — operating system identification (ext/standard/info.c parity, issue #3174). */
final class php_uname extends Internal
{
    public function __construct()
    {
        parent::__construct('php_uname');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('php_uname() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $mode = 'a';
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_STRING !== $arg->type) {
                throw new \LogicException('php_uname() mode must be a string in this compiler build');
            }
            $mode = $arg->toString();
        }
        $frame->returnVar->string(VmInfo::php_uname($mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('php_uname() accepts at most one argument');
        }
        $mode = null;
        if (isset($args[0])) {
            $mode = $this->jitString($context, $args[0], 'php_uname() mode');
        }

        return JitInfo::php_uname($context, $mode);
    }
}
