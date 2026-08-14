<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmReflection;
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

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_isUserDefined — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'isUserDefined');
        parent::execute($frame);
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
