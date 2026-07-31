<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;

/**
 * Exception/Error::__clone() — private stub for Reflection (php-src zend_exceptions.stub.php, #25870).
 *
 * Clone is rejected via ClassEntry::$denyClone (zend clone_obj = NULL), not by invoking this body.
 */
final class ExceptionClone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__clone');
    }

    public function execute(Frame $frame): void
    {
        // Unreachable when denyClone is set; listed so ReflectionClass::hasMethod('__clone') is true.
    }
}
