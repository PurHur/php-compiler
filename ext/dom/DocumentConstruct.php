<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::__construct(?string $version, ?string $encoding) — VM (php-src ext/dom/document.c; #14497). */
final class DocumentConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::__construct()');
        $state = VmDom::ensureDocument($receiver);
        $version = '1.0';
        if (isset($frame->calledArgs[1])) {
            $versionVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $versionVar->type) {
                $version = $this->stringArg(
                    $frame->calledArgs[1],
                    'DOMDocument::__construct()',
                    0,
                    $frame,
                    'version'
                );
            }
        }
        $encoding = null;
        if (isset($frame->calledArgs[2])) {
            $encodingVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $encodingVar->type) {
                $encoding = $this->stringArg(
                    $frame->calledArgs[2],
                    'DOMDocument::__construct()',
                    1,
                    $frame,
                    'encoding'
                );
            }
        }
        $state->xmlVersion = $version;
        $state->encoding = $encoding;
        VmDom::initDocumentLibxmlDefaults($receiver);
        if (null !== $frame->vmContext) {
            VmDom::ensureChildNodesList($frame->vmContext, $receiver);
        }
    }
}
