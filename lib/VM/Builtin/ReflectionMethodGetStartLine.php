<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getStartLine() — VM (#7358). */
final class ReflectionMethodGetStartLine extends ReflectionSourceGetter
{
    public function __construct()
    {
        parent::__construct('getStartLine', static function (SourceLocation $loc, ClassEntry $entry, Frame $frame): void {
            $frame->returnVar->int($loc->startLine);
        });
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getStartLine — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getStartLine');
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
