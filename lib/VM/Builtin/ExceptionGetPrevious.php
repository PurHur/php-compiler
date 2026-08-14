<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\Variable;

/** Throwable::getPrevious() — VM (#5104, #5486). */
final class ExceptionGetPrevious extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPrevious');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getPrevious() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject($frame->calledArgs[0], 'getPrevious()', $frame->vmContext);
        // php-src: Zend/zend_exceptions.c — ZEND_PARSE_PARAMETERS(0); $calledArgs[0] is $this (#30895)
        $this->requireExactUserArgCount(
            $frame,
            ExceptionSupport::throwableMethodFunctionLabel($receiver, 'getPrevious'),
            0
        );
        if (null === $frame->returnVar) {
            return;
        }
        $prev = $receiver->getProperty(ExceptionSupport::PROP_PREVIOUS)->resolveIndirect();
        if (Variable::TYPE_NULL === $prev->type) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->copyFrom($prev);
        }
    }
}
