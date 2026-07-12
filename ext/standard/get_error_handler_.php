<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_error_handler() — introspect active error handler (PHP 8.4, ext/standard/basic_functions.c; #17644).
 */
final class get_error_handler_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_error_handler');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('get_error_handler() takes no arguments');
        }
        if (null === $frame->vmContext) {
            return;
        }
        $handler = VmErrorHandler::get($frame->vmContext);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($handler);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('get_error_handler() takes no arguments');
        }

        return JitErrorHandler::get($context);
    }
}
