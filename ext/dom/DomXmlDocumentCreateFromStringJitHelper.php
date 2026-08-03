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
 * Note: thin-AOT get_class / documentElement still segfault — NestedJIT ObjectEntry* is
 * not thin __object__ layout (class_id load crashes; rewriting class_id also crashes).
 * Next: LLVM materialize Dom\XMLDocument (peer JitDomLoadXMLUserScript) + DomRegistry
 * Attr slots for rename, or NestedJIT-only get_class/property with no thin class_id (#27108).
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
