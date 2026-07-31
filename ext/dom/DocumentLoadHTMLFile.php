<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::loadHTMLFile() — VM (#18734, php-src ext/dom/php_dom.c). */
final class DocumentLoadHTMLFile extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('loadHTMLFile');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::loadHTMLFile()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::loadHTMLFile() expects at least 1 argument');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'DOMDocument::loadHTMLFile()', 0);
        $options = 0;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_LONG $options (#25768).
            $options = $this->zParamLongArg($frame, 2, 'DOMDocument::loadHTMLFile', 2, 'options');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::loadHTMLFile() requires VM context in this compiler build');
        }
        $ok = VmDom::loadHTMLFile($frame->vmContext, $receiver, $filename, $options, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
