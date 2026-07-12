<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument::loadHTML() for compiled JIT/AOT modules (#17954).
 *
 * SSOT: {@see VmDom::loadHTML()}
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class DomLoadHTMLJitHelper
{
    public static function loadHTMLArgv(Context $ctx, ObjectEntry $document, string $html, int $options): bool
    {
        return VmDom::loadHTML(
            $ctx,
            $document,
            $html,
            $options,
            null,
            false
        );
    }
}
