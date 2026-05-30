<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** phpversion() — runtime version string (ext/standard/info.c parity, issue #3174). */
final class phpversion extends Internal
{
    public function __construct()
    {
        parent::__construct('phpversion');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('phpversion() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $extension = null;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_STRING !== $arg->type) {
                    throw new \LogicException('phpversion() extension must be a string or null in this compiler build');
                }
                $extension = $arg->toString();
            }
        }
        $result = VmInfo::phpversion($extension);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('phpversion() accepts at most one argument');
        }

        return JitInfo::phpversion($context, $args[0] ?? null);
    }
}
