<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/** DOMDocument::createElementNS() for JIT/AOT modules (#14314, #18938). */
final class DomCreateElementNSJitHelper
{
    public static function createElementNSArgv(
        Context $ctx,
        ObjectEntry $document,
        ?string $namespace,
        string $qualifiedName,
        string $value = ''
    ): ObjectEntry {
        return VmDom::createElementNS($ctx, $namespace, $qualifiedName, $document, $value)->toObject();
    }
}
