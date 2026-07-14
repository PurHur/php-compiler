<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument::load() for compiled JIT/AOT modules (#18897).
 *
 * SSOT: {@see VmDom::load()}
 * php-src: ext/dom/php_dom.c — dom_document_load
 */
final class DomLoadJitHelper
{
    public static function loadArgv(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        int $options
    ): bool {
        return VmDom::load($ctx, $document, $filename, $options);
    }
}
