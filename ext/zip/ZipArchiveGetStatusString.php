<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** ZipArchive::getStatusString() — VM (#6414). */
final class ZipArchiveGetStatusString extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getStatusString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getStatusString()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmZipArchive::getStatusString($receiver));
        }
    }
}

/**
 * ZipArchive::count() — Countable entry count (php-src ext/zip/php_zip.c; #19492).
 */
final class ZipArchiveCount extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::count()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::count() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmZipArchive::numFiles($receiver));
    }
}
