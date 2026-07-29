<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** DOMDocument::createElementNS() for JIT/AOT modules (#14314, #18938, #24804). */
final class DomCreateElementNSJitHelper
{
    public static function createElementNSArgv(
        Context $ctx,
        ObjectEntry $document,
        ?string $namespace,
        string $qualifiedName,
        string $value = ''
    ): ObjectEntry {
        $var = VmDom::createElementNS($ctx, $namespace, $qualifiedName, $document, $value);
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \DOMException(
                'Namespace Error',
                DomExceptionConstants::NAMESPACE_ERR
            );
        }

        return $var->toObject();
    }
}
