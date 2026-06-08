<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getFileName() — VM (#7358). */
final class ReflectionMethodGetFileName extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getFileName', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            ReflectionSupport::returnFileName($frame->returnVar, $entry, $loc);
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
