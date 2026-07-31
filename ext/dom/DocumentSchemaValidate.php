<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::schemaValidate() — XSD validation (php-src ext/dom/document.c; #14370). */
final class DocumentSchemaValidate extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('schemaValidate');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::schemaValidate()');
        if (\count($frame->calledArgs) < 2) {
            // Optional $flags → Zend "at least" (php-src php_dom.stub.php / #25323)
            throw new \ArgumentCountError('DOMDocument::schemaValidate() expects at least 1 argument, 0 given');
        }
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
