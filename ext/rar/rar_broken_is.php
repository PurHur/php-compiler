<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\Frame;

/** rar_broken_is() — RarArchive::isBroken() (PECL rar rararch.c; #27878). */
final class rar_broken_is extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_broken_is');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'rar_broken_is', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $archive = VmRar::requireArchive($frame->calledArgs[0], 'rar_broken_is()');
            $frame->returnVar->bool(VmRar::isBroken($archive));
        } catch (\RarException|\TypeError) {
            $frame->returnVar->bool(false);
        }
    }
}
