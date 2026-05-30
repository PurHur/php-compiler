<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\Variable;

/** Generator::rewind() — start iteration (#167, Zend zend_generators.c). */
final class GeneratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $gen = self::requireGenerator($frame);
        if (null !== $gen->frame) {
            throw new \Exception('Cannot rewind a generator that was already run');
        }
        $gen->vm->resumeGenerator($gen);
    }

    private static function requireGenerator(Frame $frame): GeneratorState
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::rewind() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::rewind() called on non-object');
        }

        return GeneratorGetReturn::requireGeneratorState($receiver->toObject());
    }
}
