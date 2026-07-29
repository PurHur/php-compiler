<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMText::__construct(string $data = "")
 * — orphaned text node (php-src ext/dom/text.c; #24631).
 */
final class TextConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_TEXT, 'DOMText::__construct()');
        $data = '';
        if (isset($frame->calledArgs[1])) {
            $data = $this->stringArg(
                $frame->calledArgs[1],
                'DOMText::__construct()',
                0,
                $frame,
                'data'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMText::__construct() requires VM context in this compiler build');
        }
        VmDom::constructText($frame->vmContext, $receiver, $data);
    }
}
