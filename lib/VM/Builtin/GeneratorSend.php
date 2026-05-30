<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorState;
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
        $gen = self::generatorFromFrame($frame);
        if ($gen->done) {
            throw new \Exception('Cannot send to a closed generator');
        }
        $send = new Variable();
        $send->null();
        if (\count($frame->calledArgs) >= 2) {
            $send->copyFrom($frame->calledArgs[1]->resolveIndirect());
        }
        $active = $gen->vm->resumeGenerator($gen, $send);
        if (null === $frame->returnVar) {
            return;
        }
        if ($active && $gen->hasCurrent) {
            $frame->returnVar->copyFrom($gen->currentValue);
        } else {
            $frame->returnVar->null();
        }
    }

    private static function generatorFromFrame(Frame $frame): GeneratorState
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::send() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::send() called on non-object');
        }

        return GeneratorGetReturn::requireGeneratorState($receiver->toObject());
    }
}
