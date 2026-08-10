<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMDocument::adoptNode() for compiled JIT/AOT modules (#29853).
 *
 * SSOT: {@see VmDom::adoptNode()}
 * php-src: ext/dom/document.c — dom_document_adopt_node
 *
 * Peer: {@see DomImportNodeJitHelper} (#19212). Generic {@see VmDomInstanceInvoke}
 * NestedJIT for adoptNode aborts in thin-standalone AOT; this dedicated helper TU
 * matches the importNode bridge shape.
 *
 * Profile gate ({@see \PHPCompiler\CompilerVersion::supportsDomDocumentAdoptNode()})
 * is enforced in {@see JitDomAdoptNode} — helper-runtime objects are shared across
 * profiles and must not bake the 8.3+ path into default-profile binaries.
 */
final class DomAdoptNodeJitHelper
{
    public static function adoptNodeArgv(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $node
    ): ObjectEntry {
        $var = VmDom::adoptNode($ctx, $document, $node);
        if (Variable::TYPE_OBJECT !== $var->type) {
            // Non-strict unsupported adopt returns false (php-src RETURN_FALSE).
            throw new \DOMException('Not Supported Error', DomExceptionConstants::NOT_SUPPORTED_ERR);
        }

        return $var->toObject();
    }
}
