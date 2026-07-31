<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::save() — VM (#18435, php-src ext/dom/php_dom.c). */
final class DocumentSave extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('save');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::save()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('DOMDocument::save() expects exactly 1 argument, 0 given');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'DOMDocument::save()', 0, $frame, 'filename');
        $options = 0;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_LONG $options (#25768).
            $options = $this->zParamLongArg($frame, 2, 'DOMDocument::save', 2, 'options');
        }
        $result = VmDom::save($receiver, $filename, $options, $frame);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }
}
