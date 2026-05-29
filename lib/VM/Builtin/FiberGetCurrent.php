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
