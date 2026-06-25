<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::getDocComment() — VM (#11464, ext/reflection/php_reflection.c). */
final class ReflectionPropertyGetDocComment extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getDocComment', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            ReflectionSupport::returnDocComment($frame->returnVar, $loc->docComment);
        });
    }

    protected function resolveLocation(Frame $frame): ?SourceLocation
    {
        [$entry, $property] = self::propertyContextFromReflection($frame);
        $ctx = VmReflection::requireContext($frame);

        return ReflectionSupport::propertySourceLocation($ctx, $entry, $property);
    }

    protected function resolveEntry(Frame $frame): ClassEntry
    {
        [$entry] = self::propertyContextFromReflection($frame);

        return $entry;
    }

    /** @return array{0: ClassEntry, 1: string} */
    private static function propertyContextFromReflection(Frame $frame): array
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }

        return [$entry, $property];
    }
}
