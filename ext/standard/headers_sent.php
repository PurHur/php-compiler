<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\SapiOutput;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * headers_sent() — whether HTTP/body output has started (ext/standard/head.c, issue #3120).
 */
final class headers_sent extends Internal
{
    public function __construct()
    {
        parent::__construct('headers_sent');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \LogicException('headers_sent() accepts at most two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $sent = SapiOutput::headersSent();
        $frame->returnVar->bool($sent);
        if ($argc >= 1) {
            if ($sent) {
                $frame->calledArgs[0]->resolveIndirect()->string(SapiOutput::sentFile() ?? '');
            } else {
                $frame->calledArgs[0]->resolveIndirect()->string('');
            }
        }
        if (2 === $argc) {
            $frame->calledArgs[1]->resolveIndirect()->int($sent ? SapiOutput::sentLine() : 0);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 2) {
            throw new \LogicException('headers_sent() accepts at most two arguments');
        }

        return JitHeadersSent::invoke($context, ...$args);
    }
}
