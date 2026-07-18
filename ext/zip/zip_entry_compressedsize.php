<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_entry_compressedsize() — compressed zip entry size (ext/zip/php_zip.c; #20485). */
final class zip_entry_compressedsize extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_entry_compressedsize');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_entry_compressedsize', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmZipProcedural::requireEntryHandle($frame->calledArgs[0], 'zip_entry_compressedsize', 1);
        $size = VmZipProcedural::zipEntryCompressedsize($entry);
        if (false === $size) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($size);
    }
}
