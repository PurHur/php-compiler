<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Generator::next() — resume with null send (#167). */
final class GeneratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::next() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $calledArgs[0] is $this (#30907)
        $userArgCount = \count($frame->calledArgs) - 1;
        if (0 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'Generator::next() expects exactly 0 arguments, %d given',
                $userArgCount
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::next() called on non-object');
        }
        $gen = GeneratorGetReturn::requireGeneratorState($receiver->toObject());
        GeneratorGetReturn::ensureStarted($gen);
        if ($gen->done) {
            return;
        }
        $null = new Variable();
        $null->null();
        $gen->vm->resumeGenerator($gen, $null);
    }
}
