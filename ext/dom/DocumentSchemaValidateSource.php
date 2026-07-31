<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::schemaValidateSource() — in-memory XSD validation (php-src ext/dom/document.c; #18748). */
final class DocumentSchemaValidateSource extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('schemaValidateSource');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::schemaValidateSource()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('DOMDocument::schemaValidateSource() expects at least 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::schemaValidateSource() requires VM context in this compiler build');
        }
        $source = $this->stringArg($frame->calledArgs[1], 'DOMDocument::schemaValidateSource()', 0, $frame, 'source');
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_LONG $flags (#25768).
            $flags = $this->zParamLongArg($frame, 2, 'DOMDocument::schemaValidateSource', 2, 'flags');
        }
        $ok = VmDom::schemaValidateSource($frame->vmContext, $document, $source, $flags, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
