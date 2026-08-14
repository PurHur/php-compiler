<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmReflection;
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

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getFileName — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getFileName');
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
