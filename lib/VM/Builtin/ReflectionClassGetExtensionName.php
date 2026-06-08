<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getExtensionName() — VM (#7358). */
final class ReflectionClassGetExtensionName extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getExtensionName', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            ReflectionSupport::returnExtensionName($frame->returnVar, $entry);
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
