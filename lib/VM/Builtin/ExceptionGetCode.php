<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;

/** Throwable::getCode() — VM (#195). */
final class ExceptionGetCode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCode');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getCode() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getCode()', $frame->vmContext);
        // php-src: Zend/zend_exceptions.c — ZEND_PARSE_PARAMETERS(0); $calledArgs[0] is $this (#30895)
        $this->requireExactUserArgCount(
            $frame,
            ExceptionSupport::throwableMethodFunctionLabel($receiver, 'getCode'),
            0
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($receiver->getProperty(ExceptionSupport::PROP_CODE));
    }
}
