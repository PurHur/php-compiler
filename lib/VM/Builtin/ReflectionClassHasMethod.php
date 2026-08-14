<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::hasMethod() — VM (#6301, ext/reflection/php_reflection.c). */
final class ReflectionClassHasMethod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasMethod');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (1 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::hasMethod', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $method = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::hasMethod() name', 1);
        $frame->returnVar->bool(
            VmReflection::classHasMethodForReflection($entry, $ctx, $method, 0)
        );
    }
}
