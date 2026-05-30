<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Generator::valid() — whether iteration may continue (#167). */
final class GeneratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $gen = GeneratorGetReturn::requireGeneratorState(self::receiver($frame));
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(!$gen->done && ($gen->hasCurrent || null !== $gen->frame));
    }

    private static function receiver(Frame $frame): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::valid() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::valid() called on non-object');
        }

        return $receiver->toObject();
    }
}
