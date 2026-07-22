<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getExtension() — VM (#22100, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetExtension extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getExtension', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            $ctx = VmReflection::requireContext($frame);
            ReflectionSupport::returnExtension($frame->returnVar, $entry, $ctx);
        });
    }

    protected function resolveLocation(Frame $frame): ?SourceLocation
    {
        return null;
    }

    protected function resolveEntry(Frame $frame): ClassEntry
    {
        [$entry] = self::methodEntryFromReflection($frame, 0);

        return $entry;
    }
}
