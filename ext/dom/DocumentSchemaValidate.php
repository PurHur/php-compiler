<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::schemaValidate() — XSD validation (php-src ext/dom/document.c; #14370).
 *
 * User arity 1–2 — Zend ArgumentCountError (#31251; re-#31011 / #25323).
 */
final class DocumentSchemaValidate extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('schemaValidate');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMDocument::schemaValidate', 1, 2);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::schemaValidate()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::schemaValidate() requires VM context in this compiler build');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'DOMDocument::schemaValidate()', 0, $frame, 'filename');
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_LONG $flags (#25768).
            $flags = $this->zParamLongArg($frame, 2, 'DOMDocument::schemaValidate', 2, 'flags');
        }
        $ok = VmDom::schemaValidate($frame->vmContext, $document, $filename, $flags, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
