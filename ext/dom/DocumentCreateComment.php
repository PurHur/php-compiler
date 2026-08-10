<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createComment() — VM (#6250, php-src ext/dom/node.c). */
final class DocumentCreateComment extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createComment');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createComment()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::createComment() expects at least 1 argument');
        }
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $data = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::createComment()',
            0,
            $frame,
            'data'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createComment() requires VM context in this compiler build');
        }
        $comment = VmDom::createComment($frame->vmContext, $data, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($comment);
        }
    }
}
