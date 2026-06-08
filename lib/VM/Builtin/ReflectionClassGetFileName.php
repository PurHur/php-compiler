<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getFileName() — VM (#7358). */
final class ReflectionClassGetFileName extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getFileName', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            ReflectionSupport::returnFileName($frame->returnVar, $entry, $loc);
        });
    }

    protected function resolveLocation(Frame $frame): ?SourceLocation
    {
        return $this->resolveEntry($frame)->sourceLocation;
    }

    protected function resolveEntry(Frame $frame): ClassEntry
    {
        return self::classEntryFromReflection($frame, 0);
    }
}
