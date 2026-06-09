<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** ZipArchive::open(string $filename, int $flags = 0) — VM (#6414). */
final class ZipArchiveOpen extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('open');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::open()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::open() expects at least 1 argument, 0 given');
        }
        $filename = $this->stringArg($frame->calledArgs[1], 'ZipArchive::open', 1, 'filename');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::open', 2, 'flags')
            : 0;
        $result = VmZipArchive::open($receiver, $filename, $flags);
        if (null === $frame->returnVar) {
            return;
        }
        if (true === $result) {
            $frame->returnVar->bool(true);
        } else {
            $frame->returnVar->int((int) $result);
        }
    }
}
