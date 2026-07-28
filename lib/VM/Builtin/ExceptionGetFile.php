<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;

/** Throwable::getFile() — VM (#195). */
final class ExceptionGetFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFile');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getFile() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getFile()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        // Typed file may still be an uninit prototype on older engine Error paths (#24397).
        $file = ExceptionSupport::readThrowableFile($receiver);
        $frame->returnVar->string($file);
    }
}
