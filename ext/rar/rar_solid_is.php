<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\Frame;

/** rar_solid_is() — RarArchive::isSolid() (PECL rar rararch.c; #27878). */
final class rar_solid_is extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_solid_is');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'rar_solid_is', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $archive = VmRar::requireArchive($frame->calledArgs[0], 'rar_solid_is()');
            $frame->returnVar->bool(VmRar::isSolid($archive));
        } catch (\RarException|\TypeError) {
            $frame->returnVar->bool(false);
        }
    }
}
