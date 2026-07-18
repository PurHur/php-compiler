<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** ZipArchive::close() — VM (#6414). */
final class ZipArchiveClose extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::close()');
        $ok = VmZipArchive::close($receiver, $frame->vmContext);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
