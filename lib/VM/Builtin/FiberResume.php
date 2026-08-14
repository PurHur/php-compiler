<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\FiberSupport;
use PHPCompiler\VM\Variable;

/** Fiber::resume(mixed $value = null): mixed — VM (#3130). */
final class FiberResume extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('resume');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Fiber::resume() called without $this');
        }
        // php-src Zend/zend_fibers.stub.php — resume(mixed $value = null); ZEND_NUM_ARGS at most 1 (#30906)
        $this->requireUserArgCountRange($frame, 'Fiber::resume', 0, 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('Fiber::resume() requires VM context');
        }
        FiberSupport::requireFiberObject($frame->calledArgs[0], 'Fiber::resume()');
        $fiber = FiberSupport::fiberFromObject($frame->calledArgs[0]->resolveIndirect()->toObject());
        $resumeArgs = [];
        if (\count($frame->calledArgs) >= 2) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[1]);
            $resumeArgs[] = $copy;
        }
        $result = $frame->vmContext->runtime->vm->resumeFiber($fiber, ...$resumeArgs);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
