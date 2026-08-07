<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_exception_handler() — introspect active exception handler (PHP 8.5, ext/standard/basic_functions.c; #17644).
 *
 * Excess argc → ArgumentCountError (#28683; peer #28690).
 */
final class get_exception_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('get_exception_handler');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#28683).
        $this->requireExactArgCount($frame, 'get_exception_handler', 0);
        if (null === $frame->vmContext) {
            return;
        }
        $handler = VmExceptionHandler::get($frame->vmContext);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($handler);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28683.
        if (!$this->requireExactJitArgCount($context, $args, 'get_exception_handler', 0)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        return JitExceptionHandler::get($context);
    }
}
