<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\Variable;

/** Closure::fromCallable() — VM (#3266). */
final class ClosureFromCallable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromCallable');
    }

    public function execute(Frame $frame): void
    {
        // Static factory — calledArgs are user args only (php-src zim_Closure_fromCallable, #30930).
        $this->requireExactArgCount($frame, 'Closure::fromCallable', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('Closure::fromCallable() requires VM context');
        }
        $entry = ClosureSupport::fromCallable(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[0],
            null,
            true
        );
        if (null !== $frame->returnVar) {
            $ret = new Variable(Variable::TYPE_OBJECT);
            $ret->object($entry);
            $frame->returnVar->copyFrom($ret);
        }
    }
}
