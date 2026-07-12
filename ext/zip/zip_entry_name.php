<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_entry_name() — zip entry filename (ext/zip/php_zip.c; #6370). */
final class zip_entry_name extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_entry_name');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_entry_name', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmZipProcedural::requireEntryHandle($frame->calledArgs[0], 'zip_entry_name', 1);
        $name = VmZipProcedural::zipEntryName($entry);
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }
}
