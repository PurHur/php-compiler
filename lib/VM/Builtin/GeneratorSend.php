<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Generator::send() — resume with value into yield expression (#167, Zend zend_generators.c). */
final class GeneratorSend extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('send');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::send() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (1 arg); $calledArgs[0] is $this (#30907)
        $userArgCount = \count($frame->calledArgs) - 1;
        if (1 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'Generator::send() expects exactly 1 argument, %d given',
                $userArgCount
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::send() called on non-object');
        }
        $gen = GeneratorGetReturn::requireGeneratorState($receiver->toObject());
        if ($gen->done) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        $send = new Variable();
        $send->copyFrom($frame->calledArgs[1]->resolveIndirect());
        $active = $gen->vm->resumeGenerator($gen, $send);
        if (null === $frame->returnVar) {
            return;
        }
        if ($active && $gen->hasCurrent) {
            $staging = new Variable();
            $staging->duplicateFrom($gen->currentSnapshot);
            $frame->returnVar->copyFrom($staging);
        } else {
            $frame->returnVar->null();
        }
    }
}
