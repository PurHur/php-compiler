<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/**
 * Former ReflectionClass::isStatic() implementation (#6929).
 *
 * Unregistered on every profile: static-class RFC never merged; php-src stub has isStatic only on
 * ReflectionFunctionAbstract / ReflectionProperty (#28518). Kept loadable for spine/inventory
 * stability — do not re-register on ReflectionClass.
 */
final class ReflectionClassIsStatic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isStatic');
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
            $frame->returnVar->bool($entry->isStatic);
        }
    }
}
