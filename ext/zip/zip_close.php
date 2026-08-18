<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_close() — close zip archive (ext/zip/php_zip.c; #6370). */
final class zip_close extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_close');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_close', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmZipProcedural::requireArchiveHandle($frame->calledArgs[0], 'zip_close', 1, 'zip');
        $frame->returnVar->bool(VmZipProcedural::zipClose($handle));
    }
}
