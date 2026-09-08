<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionClass::getDefaultProperties() — VM (#11441, ext/reflection/php_reflection.c). */
final class ReflectionClassGetDefaultProperties extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDefaultProperties');
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
            $frame->returnVar->copyFrom(VmReflection::getDefaultPropertiesArray($entry, $ctx));
        }
    }
}
