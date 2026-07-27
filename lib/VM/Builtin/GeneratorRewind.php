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
        // Zend zend_generator_rewind — no-op while AT_FIRST_YIELD (#23713).
        self::requireGenerator($frame)->rewind();
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
