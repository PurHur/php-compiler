<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getEndLine() — VM (#7358). */
final class ReflectionMethodGetEndLine extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getEndLine', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            $frame->returnVar->int($loc->endLine);
        });
    }

    protected function resolveLocation(Frame $frame): ?SourceLocation
    {
        [$entry, $methodLc] = self::methodEntryFromReflection($frame, 0);

        return ReflectionSupport::methodSourceLocation($entry, $methodLc);
    }

    protected function resolveEntry(Frame $frame): ClassEntry
    {
        [$entry] = self::methodEntryFromReflection($frame, 0);

        return $entry;
    }
}
