<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;

/** ZipArchive::__construct() — initialize internal state (#6414). */
final class ZipArchiveConstruct extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('ZipArchive::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('ZipArchive::__construct() must be called on ZipArchive');
        }
        VmZipArchive::initObject($var->toObject());
    }
}
