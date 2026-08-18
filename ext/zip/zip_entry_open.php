<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** zip_entry_open() — open zip entry for reading (ext/zip/php_zip.c; #6370). */
final class zip_entry_open extends ZipProceduralFunction
{
    public function __construct()
    {
        parent::__construct('zip_entry_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'zip_entry_open() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $archive = VmZipProcedural::requireArchiveHandle($frame->calledArgs[0], 'zip_entry_open', 1, 'zip_dp');
        $entry = VmZipProcedural::requireEntryHandle($frame->calledArgs[1], 'zip_entry_open', 2);
        $mode = 3 === $argc
            ? VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'zip_entry_open', 3, 'mode')
            : 'rb';
        $frame->returnVar->bool(VmZipProcedural::zipEntryOpen($archive, $entry, $mode));
    }
}
