<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;

/** ErrorException::getSeverity() — VM (#6732). */
final class ErrorExceptionGetSeverity extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSeverity');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getSeverity() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getSeverity()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($receiver->getProperty(ExceptionSupport::PROP_SEVERITY));
    }
}
