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
