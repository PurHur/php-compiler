<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isConstructor() — VM (#18225, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsConstructor extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isConstructor');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionMethod_isConstructor — ZEND_PARSE_PARAMETERS (0 args) (#31127)
        $this->requireExactUserArgCount($frame, 'ReflectionMethod::isConstructor', 0);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionMethodConstructor($receiver));
        }
    }
}
