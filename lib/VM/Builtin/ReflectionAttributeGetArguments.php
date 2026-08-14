<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionAttribute::getArguments() — VM read path (#3340). */
final class ReflectionAttributeGetArguments extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getArguments');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args); $calledArgs[0] is $this (#30896)
        $this->requireExactUserArgCount($frame, 'ReflectionAttribute::getArguments', 0);
        $receiver = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $args = ReflectionSupport::argsFromReflectionObject($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(ReflectionSupport::argumentsArray($args, $ctx));
        }
    }
}
