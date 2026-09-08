<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionClass::isInternal() — VM (#5011, #5937). */
final class ReflectionClassIsInternal extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInternal');
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
            $frame->returnVar->bool($entry->isInternal);
        }
    }
}
