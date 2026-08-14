<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmReflection;
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

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getEndLine — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getEndLine');
        parent::execute($frame);
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
