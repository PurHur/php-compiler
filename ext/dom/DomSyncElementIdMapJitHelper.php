<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** Mirror DomRegistry elementIds after loadHTML for LLVM getElementById() (#17954). */
final class DomSyncElementIdMapJitHelper
{
    public static function syncArgv(ObjectEntry $document): void
    {
        VmDom::syncElementIdMapProperty($document);
    }
}
