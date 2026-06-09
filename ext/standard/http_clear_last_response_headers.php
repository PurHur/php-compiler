<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * http_clear_last_response_headers() — clear stored HTTP wrapper response headers (ext/standard/http.c, #7024).
 */
final class http_clear_last_response_headers extends Internal
{
    public function __construct()
    {
        parent::__construct('http_clear_last_response_headers');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('http_clear_last_response_headers() takes no arguments');
        }
        VmHttpLastResponseHeaders::clear();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('http_clear_last_response_headers() takes no arguments');
        }
        JitHttpLastResponseHeaders::clear($context);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
