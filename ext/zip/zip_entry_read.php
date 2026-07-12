<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** zip_entry_read() — read from open zip entry (ext/zip/php_zip.c; #6370). */
final class zip_entry_read extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_entry_read');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'zip_entry_read() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmZipProcedural::requireEntryHandle($frame->calledArgs[0], 'zip_entry_read', 1);
        $length = 2 === $argc
            ? VmZipArchive::coerceIntArg($frame->calledArgs[1], 'zip_entry_read', 2, 'length', 1024)
            : 1024;
        $data = VmZipProcedural::zipEntryRead($entry, $length);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }
}
