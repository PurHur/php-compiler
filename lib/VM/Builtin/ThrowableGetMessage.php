<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExceptionSupport;
use PHPCompiler\VM\Variable;

/** Error::getMessage() / TypeError::getMessage() — VM (#3445). */
final class ThrowableGetMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMessage');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('getMessage() called without object');
        }
        $message = $receiver->toObject()->getProperty(BuiltinExceptionSupport::PROP_MESSAGE)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($message);
        }
    }
}
