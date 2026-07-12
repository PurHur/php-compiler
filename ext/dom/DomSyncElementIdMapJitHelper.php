<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/** Mirror DomRegistry elementIds after loadHTML for LLVM getElementById() (#17954). */
final class DomSyncElementIdMapJitHelper
{
    public static function syncArgv(int $documentId): void
    {
        $document = DomRegistry::entry($documentId);
        if (null === $document) {
            return;
        }
        VmDom::syncElementIdMapProperty($document);
    }
}
