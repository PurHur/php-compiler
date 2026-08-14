<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** Reflection*Type::allowsNull() — VM (#3355). */
final class ReflectionTypeAllowsNull extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('allowsNull');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ReflectionType::allowsNull ZEND_PARSE_PARAMETERS (0) (#30896)
        $this->requireExactUserArgCount($frame, 'ReflectionType::allowsNull', 0);
        $receiver = ReflectionSupport::requireReflectionType($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::allowsNullFromReflection($receiver));
        }
    }
}
