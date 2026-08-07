<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * http_get_last_response_headers() — last HTTP wrapper response header list (ext/standard/basic_functions.c, #7236).
 *
 * Excess argc → ArgumentCountError (#28683; peer #28690).
 */
class http_get_last_response_headers extends Internal
{
    public function __construct(?string $name = null)
    {
        parent::__construct($name ?? 'http_get_last_response_headers');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/http.c — ArgumentCountError (#28683).
        $this->requireExactArgCount($frame, $this->getName(), 0);
        if (null === $frame->returnVar) {
            return;
        }
        $headers = VmHttpLastResponseHeaders::get();
        if (null === $headers) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray($headers));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28683.
        if (!$this->requireExactJitArgCount($context, $args, $this->getName(), 0)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        return JitHttpLastResponseHeaders::invoke($context);
    }
}
