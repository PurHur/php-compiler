<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;

/** ReflectionMethod::isUserDefined() — VM (#7358). */
final class ReflectionMethodIsUserDefined extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('isUserDefined', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            $frame->returnVar->bool(!$entry->isInternal);
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
