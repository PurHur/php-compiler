<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_entry_compressionmethod() — zip entry compression method name (ext/zip/php_zip.c; #20485). */
final class zip_entry_compressionmethod extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_entry_compressionmethod');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'zip_entry_compressionmethod', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmZipProcedural::requireEntryHandle($frame->calledArgs[0], 'zip_entry_compressionmethod', 1);
        $method = VmZipProcedural::zipEntryCompressionmethod($entry);
        if (false === $method) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($method);
    }
}
