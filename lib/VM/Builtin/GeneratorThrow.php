<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\Variable;

/** Generator::throw() — inject exception at yield suspension (#167, Zend zend_generators.c). */
final class GeneratorThrow extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('throw');
    }

    public function execute(Frame $frame): void
    {
        $gen = self::generatorFromFrame($frame);
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('Generator::throw() requires an exception argument');
        }
        $exception = $frame->calledArgs[1]->resolveIndirect();
        $active = $gen->vm->throwGenerator($gen, $exception);
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
            throw new \LogicException('Generator::throw() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::throw() called on non-object');
        }

        return GeneratorGetReturn::requireGeneratorState($receiver->toObject());
    }
}
