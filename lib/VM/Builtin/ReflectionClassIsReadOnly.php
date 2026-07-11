<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isReadOnly() — VM (#5221, ext/reflection/php_reflection.c). */
final class ReflectionClassIsReadOnly extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isReadOnly');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($entry->readonly);
        }
    }
}
