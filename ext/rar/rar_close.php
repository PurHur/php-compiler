<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\Frame;

/** rar_close() — RarArchive::close() (PECL rar rararch.c; #27878). */
final class rar_close extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_close');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'rar_close', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $archive = VmRar::requireArchive($frame->calledArgs[0], 'rar_close()');
            $frame->returnVar->bool(VmRar::close($archive));
        } catch (\RarException|\TypeError) {
            $frame->returnVar->bool(false);
        }
    }
}
