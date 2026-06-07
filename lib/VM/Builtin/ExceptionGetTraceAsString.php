<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\ExceptionTraceFormat;

/** Throwable::getTraceAsString() — VM (#7159). */
final class ExceptionGetTraceAsString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTraceAsString');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getTraceAsString() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getTraceAsString()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $trace = $receiver->getProperty(ExceptionSupport::PROP_TRACE);
        $frame->returnVar->string(ExceptionTraceFormat::asString($trace));
    }
}
