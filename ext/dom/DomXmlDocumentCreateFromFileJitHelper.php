<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Dom\XMLDocument::createFromFile() for compiled JIT/AOT modules (leftover of #27108).
 *
 * php-src: ext/dom/xml_document.c — Dom\XMLDocument::createFromFile
 */
final class DomXmlDocumentCreateFromFileJitHelper
{
    public static function createFromFileArgv(
        Context $ctx,
        string $path,
        int $options = 0
    ): ObjectEntry {
        $var = VmDomLiving::createXmlFromFile($ctx, $path, $options);
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException(
                'Invalid State Error',
                DomExceptionConstants::INVALID_STATE_ERR
            );
        }

        return $var->toObject();
    }
}
