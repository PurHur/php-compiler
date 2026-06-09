<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** ZipArchive::addFile(string $filepath, string $entryname = "") — VM (#6414). */
final class ZipArchiveAddFile extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('addFile');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::addFile()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::addFile() expects at least 1 argument, 0 given');
        }
        $filepath = $this->stringArg($frame->calledArgs[1], 'ZipArchive::addFile', 1, 'filepath');
        $entryname = '';
        if (\count($frame->calledArgs) >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $entryname = $this->stringArg($frame->calledArgs[2], 'ZipArchive::addFile', 2, 'entryname');
            }
        }
        $ok = VmZipArchive::addFile($receiver, $filepath, $entryname);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
