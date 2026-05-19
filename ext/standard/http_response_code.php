<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * http_response_code() — get/set HTTP status for the current response (VM only; issue #252).
 */
final class http_response_code extends Internal
{
    public function __construct()
    {
        parent::__construct('http_response_code');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('http_response_code() accepts at most one argument');
        }
        if (0 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->int(ResponseContext::getStatus());
            }

            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->int(ResponseContext::getStatus());
            }

            return;
        }
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \LogicException('http_response_code() response_code must be an integer in this compiler build');
        }
        $previous = ResponseContext::getStatus();
        if (!ResponseContext::setStatus($arg->toInt())) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($previous);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('http_response_code() is not implemented for JIT in this compiler build');
    }
}
