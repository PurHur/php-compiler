<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMComment::__construct(string $data = "")
 * — orphaned comment (php-src ext/dom/comment.c; #24631).
 */
final class CommentConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_COMMENT, 'DOMComment::__construct()');
        $data = '';
        if (isset($frame->calledArgs[1])) {
            $data = $this->stringArg(
                $frame->calledArgs[1],
                'DOMComment::__construct()',
                0,
                $frame,
                'data'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMComment::__construct() requires VM context in this compiler build');
        }
        VmDom::constructComment($frame->vmContext, $receiver, $data);
    }
}
