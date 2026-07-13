<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument::loadHTMLFile() for compiled JIT/AOT modules (#18734).
 *
 * SSOT: {@see VmDom::loadHTMLFile()}
 * php-src: ext/dom/php_dom.c — dom_document_load_html_file
 */
final class DomLoadHTMLFileJitHelper
{
    public static function loadHTMLFileArgv(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        int $options
    ): bool {
        return VmDom::loadHTMLFile($ctx, $document, $filename, $options);
    }
}
