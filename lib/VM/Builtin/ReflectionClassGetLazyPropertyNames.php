<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionClass::getLazyPropertyNames() — VM (#6606, ext/reflection/php_reflection.c). */
final class ReflectionClassGetLazyPropertyNames extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLazyPropertyNames');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-class-unknown');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                VmReflection::reflectionLazyPropertyNamesArray($ctx, $entry)
            );
        }
    }
}
