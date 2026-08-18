<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_read() — read next zip directory entry (ext/zip/php_zip.c; #6370). */
final class zip_read extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_read');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_read', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $archive = VmZipProcedural::requireArchiveHandle($frame->calledArgs[0], 'zip_read', 1, 'zip');
        $entry = VmZipProcedural::zipRead($archive);
        if (false === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($entry, $frame->vmContext);
    }
}
