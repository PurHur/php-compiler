<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;

/** Throwable::__wakeup() — introspection-only stub (php-src zend_exceptions.c). */
final class ExceptionWakeup extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__wakeup');
    }

    public function execute(Frame $frame): void
    {
        // php-src internal exception __wakeup is a no-op; listed for get_class_methods().
    }
}
