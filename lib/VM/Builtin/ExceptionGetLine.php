<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;

/** Throwable::getLine() — VM (#195). */
final class ExceptionGetLine extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLine');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getLine() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getLine()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($receiver->getProperty(ExceptionSupport::PROP_LINE));
    }
}
