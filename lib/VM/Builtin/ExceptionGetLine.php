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
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getLine()', $frame->vmContext);
        // php-src: Zend/zend_exceptions.c — ZEND_PARSE_PARAMETERS(0); $calledArgs[0] is $this (#30895)
        $this->requireExactUserArgCount(
            $frame,
            ExceptionSupport::throwableMethodFunctionLabel($receiver, 'getLine'),
            0
        );
        if (null === $frame->returnVar) {
            return;
        }
        // Typed line may still be an uninit prototype on older engine Error paths (#24397).
        $line = ExceptionSupport::readThrowableLine($receiver);
        $frame->returnVar->int($line);
    }
}
