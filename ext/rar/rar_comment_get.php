<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\Frame;

/** rar_comment_get() — RarArchive::getComment() (PECL rar rararch.c; #27878). */
final class rar_comment_get extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_comment_get');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'rar_comment_get', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $archive = VmRar::requireArchive($frame->calledArgs[0], 'rar_comment_get()');
            $comment = VmRar::getComment($archive);
            if ('' === $comment) {
                $frame->returnVar->null();

                return;
            }
            $frame->returnVar->string($comment);
        } catch (\RarException|\TypeError) {
            $frame->returnVar->bool(false);
        }
    }
}
