<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_entry_close() — close zip entry (ext/zip/php_zip.c; #6370). */
final class zip_entry_close extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_entry_close');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_entry_close', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmZipProcedural::requireEntryHandle($frame->calledArgs[0], 'zip_entry_close', 1);
        $frame->returnVar->bool(VmZipProcedural::zipEntryClose($entry));
    }
}
