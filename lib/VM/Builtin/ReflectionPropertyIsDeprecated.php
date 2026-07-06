<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::isDeprecated() — VM (#9768, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsDeprecated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDeprecated');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $declaringName = ReflectionSupport::declaringClassNameFromReflectionProperty($receiver, $ctx);
        $entry = VmReflection::resolveClassEntry($ctx, $declaringName);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $propLc = strtolower($property);
        if (null !== $frame->returnVar) {
            $meta = $entry->propDeprecated[$propLc] ?? null;
            $frame->returnVar->bool(
                null !== $meta && $meta->isDeprecatedForReflection()
            );
        }
    }
}
