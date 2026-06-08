<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\Variable;

/**
 * ErrorException::__construct — severity/file/line slots (Zend/zend_exceptions.c, #6732).
 */
final class ErrorExceptionConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('ErrorException::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ErrorException::__construct() called without $this');
        }
        ExceptionSupport::initErrorExceptionFromConstruct($receiver->toObject(), $frame);
    }
}
