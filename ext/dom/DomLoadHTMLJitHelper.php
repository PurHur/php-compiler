<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * DOMDocument::loadHTML() for compiled JIT/AOT modules (#17954).
 *
 * SSOT: {@see VmDom::loadHTML()}
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class DomLoadHTMLJitHelper
{
    public static function loadHTMLArgv(int $documentId, string $html, int $options): bool
    {
        $document = DomRegistry::entry($documentId);
        if (null === $document) {
            return false;
        }

        return VmDom::loadHTML(
            VmDomJitFrame::vmContext(),
            $document,
            $html,
            $options,
            null,
            true
        );
    }
}
