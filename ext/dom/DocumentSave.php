<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

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
            $optionsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError('DOMDocument::save(): Argument #2 ($options) must be of type int');
            }
            $options = $optionsVar->toInt();
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
