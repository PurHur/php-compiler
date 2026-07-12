<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_entry_filesize() — uncompressed zip entry size (ext/zip/php_zip.c; #6370). */
final class zip_entry_filesize extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_entry_filesize');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_entry_filesize', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmZipProcedural::requireEntryHandle($frame->calledArgs[0], 'zip_entry_filesize', 1);
        $size = VmZipProcedural::zipEntryFilesize($entry);
        if (false === $size) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($size);
    }
}
