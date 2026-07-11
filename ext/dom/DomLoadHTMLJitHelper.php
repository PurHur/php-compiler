<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableObject;

/**
 * DOMDocument::loadHTML() for compiled JIT/AOT modules (#17954).
 *
 * SSOT: {@see VmDom::loadHTML()}
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class DomLoadHTMLJitHelper
{
    public static function loadHTMLArgv(Variable $receiver, string $html, int $options): bool
    {
        $document = VariableObject::entry($receiver);

        return VmDom::loadHTML(
            VmDomJitFrame::vmContext(),
            $document,
            $html,
            $options,
            null,
            false
        );
    }
}
