<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMDocument::loadHTML() for compiled JIT/AOT modules (#17954).
 *
 * SSOT: {@see VmDom::loadHTML()}
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class DomLoadHTMLJitHelper
{
    public static function loadHTMLArgv(ObjectEntry $document, Variable $html, int $options): bool
    {
        $htmlStr = VmString::coerceStringBuiltinArg(
            $html->resolveIndirect(),
            'DOMDocument::loadHTML()',
            0,
            'source'
        );

        return VmDom::loadHTML(VmDomJitFrame::vmContext(), $document, $htmlStr, $options, null, true);
    }
}
