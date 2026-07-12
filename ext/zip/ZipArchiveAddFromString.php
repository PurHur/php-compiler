<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** ZipArchive::addFromString(string $name, string $content) — VM (#3337). */
final class ZipArchiveAddFromString extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('addFromString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::addFromString()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('ZipArchive::addFromString() expects exactly 2 arguments, ' . (\count($frame->calledArgs) - 1) . ' given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::addFromString', 1, 'name');
        $content = $this->stringArg($frame->calledArgs[2], 'ZipArchive::addFromString', 2, 'content');
        $ok = VmZipArchive::addFromString($receiver, $name, $content);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
