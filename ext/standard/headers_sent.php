<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
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
        // php-src ext/standard/head.c — ArgumentCountError (#30705).
        $this->requireAtMostArgCount($frame, 'headers_sent', 2);
        $argc = \count($frame->calledArgs);
        $sent = SapiOutput::headersSent();
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
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($sent);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer headers_list / #30705.
        if (!$this->requireAtMostJitArgCount($context, $args, 'headers_sent', 2)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitHeadersSent::invoke($context, ...$args);
    }
}
