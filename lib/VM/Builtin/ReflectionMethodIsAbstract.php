<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isAbstract() — VM (#18225, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsAbstract extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isAbstract');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionMethod_isAbstract — ZEND_PARSE_PARAMETERS (0 args) (#31127)
        $this->requireExactUserArgCount($frame, 'ReflectionMethod::isAbstract', 0);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionMethodAbstract($ctx, $receiver));
        }
    }
}
