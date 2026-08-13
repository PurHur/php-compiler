<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::getElementsByTagName() — VM (php-src ext/dom/php_dom.c; issue #14336). */
final class DocumentGetElementsByTagName extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementsByTagName');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::getElementsByTagName', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::getElementsByTagName()');
        // Pass $frame so caller strict_types rejects null like Zend (#29959, re-#29942 / #18215).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::getElementsByTagName()',
            0,
            $frame,
            'qualifiedName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::getElementsByTagName() requires VM context in this compiler build');
        }
        $list = VmDom::getElementsByTagName($frame->vmContext, $receiver, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
        }
    }
}
