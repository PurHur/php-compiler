<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;

/** Throwable::getMessage() — VM (#195). */
final class ExceptionGetMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMessage');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getMessage() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getMessage()', $frame->vmContext);
        // php-src: Zend/zend_exceptions.c — ZEND_PARSE_PARAMETERS(0); $calledArgs[0] is $this (#30895)
        $this->requireExactUserArgCount(
            $frame,
            ExceptionSupport::throwableMethodFunctionLabel($receiver, 'getMessage'),
            0
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($receiver->getProperty(ExceptionSupport::PROP_MESSAGE));
    }
}
