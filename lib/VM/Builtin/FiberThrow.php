<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\FiberSupport;
use PHPCompiler\VM\Variable;

/** Fiber::throw(Throwable $exception): mixed — VM (Zend/zend_fibers.c parity, #4481). */
final class FiberThrow extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('throw');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::throw() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — throw(Throwable $exception); ZEND_NUM_ARGS exactly 1 (#30906)
        $this->requireExactUserArgCount($frame, 'Fiber::throw', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('Fiber::throw() requires VM context');
        }
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::throw()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());

        $exception = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $exception->type) {
            throw new \TypeError(
                'Fiber::throw(): Argument #1 ($exception) must be of type Throwable, '
                .self::valueTypeName($exception).' given'
            );
        }
        ExceptionSupport::requireThrowableObject($exception, 'Fiber::throw() argument', $frame->vmContext);

        $result = $frame->vmContext->runtime->vm->throwFiber($fiber, $exception);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    private static function valueTypeName(Variable $value): string
    {
        return EnumCaseSupport::typeNameForVariable($value);
    }
}

