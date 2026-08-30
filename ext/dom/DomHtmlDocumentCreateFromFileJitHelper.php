<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Dom\HTMLDocument::createFromFile() for compiled JIT/AOT modules (leftover of #27300).
 *
 * php-src: ext/dom/html_document.c — Dom\HTMLDocument::createFromFile
 */
final class DomHtmlDocumentCreateFromFileJitHelper
{
    public static function createFromFileArgv(
        Context $ctx,
        string $path,
        int $options = 0
    ): ObjectEntry {
        $var = VmDomLiving::createFromFile($ctx, $path, $options);
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException(
                'Invalid State Error',
                DomExceptionConstants::INVALID_STATE_ERR
            );
        }

        return $var->toObject();
    }
}
