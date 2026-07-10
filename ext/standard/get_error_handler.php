<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_error_handler() — introspect active user error handler (PHP 8.4, #17644).
 *
 * php-src: ext/standard/basic_functions.c (PHP_FUNCTION(get_error_handler)).
 */
final class get_error_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('get_error_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('get_error_handler() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $frame->returnVar->copyFrom(VmErrorHandler::get($frame->vmContext));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError('get_error_handler() expects exactly 0 arguments, '.\count($args).' given');
        }
        throw new \LogicException('get_error_handler() is not implemented for JIT in this compiler build');
    }
}
