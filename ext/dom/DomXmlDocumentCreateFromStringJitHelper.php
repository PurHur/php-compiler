<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Dom\XMLDocument::createFromString() for compiled JIT/AOT modules (#27108 / #19581).
 *
 * php-src: ext/dom/xml_document.c — load_from_helper(DOM_LOAD_STRING)
 *
 * Note: thin-AOT get_class / property fetch on the returned living document still needs
 * Dom\ class_id sync or LLVM materialization (peer JitDomLoadXMLUserScript) — see #27108.
 */
final class DomXmlDocumentCreateFromStringJitHelper
{
    public static function createFromStringArgv(
        Context $ctx,
        string $source,
        int $options = 0
    ): ObjectEntry {
        $var = VmDomLiving::createXmlFromString($ctx, $source, $options);
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException(
                'Invalid State Error',
                DomExceptionConstants::INVALID_STATE_ERR
            );
        }

        return $var->toObject();
    }
}
