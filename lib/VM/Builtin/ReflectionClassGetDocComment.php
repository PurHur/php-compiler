<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getDocComment() — VM (#7358). */
final class ReflectionClassGetDocComment extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getDocComment', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            ReflectionSupport::returnDocComment($frame->returnVar, $loc->docComment);
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
