<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionReference::__construct — always throws (ext/reflection/php_reflection.c). */
final class ReflectionReferenceConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        ReflectionSupport::throwReflectionException(
            'Cannot directly instantiate ReflectionReference'
        );
    }
}
