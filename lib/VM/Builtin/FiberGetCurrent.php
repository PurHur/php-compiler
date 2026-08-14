<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** Fiber::getCurrent(): ?Fiber — VM (#3130). */
final class FiberGetCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCurrent');
    }

    public function execute(Frame $frame): void
    {
        // php-src Zend/zend_fibers.stub.php — static getCurrent(): ?Fiber; ZEND_PARSE_PARAMETERS_NONE (#30906)
        $this->requireExactArgCount($frame, 'Fiber::getCurrent', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('Fiber::getCurrent() requires VM context');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $current = $frame->vmContext->currentFiber;
        if (null === $current) {
            $frame->returnVar->null();

            return;
        }
        $ref = new Variable(Variable::TYPE_OBJECT);
        $ref->object($current->object);
        $frame->returnVar->copyFrom($ref);
    }
}
