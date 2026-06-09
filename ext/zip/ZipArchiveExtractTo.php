<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** ZipArchive::extractTo(string $pathto, array|string|null $files = null) — VM (#6414). */
final class ZipArchiveExtractTo extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('extractTo');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::extractTo()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::extractTo() expects at least 1 argument, 0 given');
        }
        $pathto = $this->stringArg($frame->calledArgs[1], 'ZipArchive::extractTo', 1, 'pathto');
        $files = null;
        if (\count($frame->calledArgs) >= 3) {
            $arg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $files = $frame->calledArgs[2];
            }
        }
        $ok = VmZipArchive::extractTo($receiver, $pathto, $files);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
