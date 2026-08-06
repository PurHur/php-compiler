<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Dom\HTMLDocument::createFromString() for compiled JIT/AOT modules (#27300).
 *
 * php-src: ext/dom/html_document.c — Dom\HTMLDocument::createFromString
 *
 * Thin-AOT prefers LLVM materialize in {@see JitDomHtmlDocumentCreateFromString}
 * (NestedJIT ObjectEntry* is not thin __object__ layout — peer #27108).
 */
final class DomHtmlDocumentCreateFromStringJitHelper
{
    public static function createFromStringArgv(
        Context $ctx,
        string $source,
        int $options = 0
    ): ObjectEntry {
        $var = VmDomLiving::createFromString($ctx, $source, $options);
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException(
                'Invalid State Error',
                DomExceptionConstants::INVALID_STATE_ERR
            );
        }

        return $var->toObject();
    }
}
