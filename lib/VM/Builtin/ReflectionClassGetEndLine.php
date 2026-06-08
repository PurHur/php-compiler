<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;

/** ReflectionClass::getEndLine() — VM (#7358). */
final class ReflectionClassGetEndLine extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getEndLine', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            $frame->returnVar->int($loc->endLine);
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
