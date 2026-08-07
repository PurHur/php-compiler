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
 *
 * Excess argc → ArgumentCountError (#28683; peer #28690).
 */
final class http_clear_last_response_headers extends Internal
{
    public function __construct()
    {
        parent::__construct('http_clear_last_response_headers');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/http.c — ArgumentCountError (#28683).
        $this->requireExactArgCount($frame, 'http_clear_last_response_headers', 0);
        VmHttpLastResponseHeaders::clear();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28683.
        if (!$this->requireExactJitArgCount($context, $args, 'http_clear_last_response_headers', 0)) {
            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        JitHttpLastResponseHeaders::clear($context);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
