<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** rar_entry_get() — RarArchive::getEntry() (PECL rar rararch.c; #27878). */
final class rar_entry_get extends RarProceduralFunction
{
    public function __construct()
    {
        parent::__construct('rar_entry_get');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'rar_entry_get', 2);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('rar_entry_get() requires an active VM context');
        }
        try {
            $archive = VmRar::requireArchive($frame->calledArgs[0], 'rar_entry_get()');
            $name = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'rar_entry_get', 1, 'entryname');
            $entry = VmRar::getEntry($archive, $frame->vmContext, $name);
        } catch (\RarException|\TypeError) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($entry);
    }
}
