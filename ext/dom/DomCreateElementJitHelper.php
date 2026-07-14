<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/** DOMDocument::createElement() with optional value for JIT/AOT (#18938). */
final class DomCreateElementJitHelper
{
    public static function createElementArgv(
        Context $ctx,
        ObjectEntry $document,
        string $name,
        string $value = ''
    ): ObjectEntry {
        return VmDom::createElement($ctx, $name, $document, $value)->toObject();
    }
}
