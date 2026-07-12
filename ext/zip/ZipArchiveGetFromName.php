<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** ZipArchive::getFromName(string $name) — VM (#3337). */
final class ZipArchiveGetFromName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getFromName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getFromName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getFromName() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::getFromName', 1, 'name');
        $result = VmZipArchive::getFromName($receiver, $name);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }
}
