<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getExtension() — VM (#11462). */
final class ReflectionClassGetExtension extends ReflectionSourceGetter
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
        return self::classEntryFromReflection($frame, 0);
    }
}
