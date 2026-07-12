<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Generator::current() — yielded value (#167). */
final class GeneratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $gen = GeneratorGetReturn::requireGeneratorState(self::receiver($frame));
        GeneratorGetReturn::ensureStarted($gen);
        if (null === $frame->returnVar) {
            return;
        }
        if ($gen->hasCurrent) {
            // FUNCCALL result slots may alias generator/$this storage (#1885, #18183).
            $staging = new Variable();
            $staging->copyFrom($gen->currentValue);
            $frame->returnVar->copyFrom($staging);
        } else {
            $frame->returnVar->null();
        }
    }

    private static function receiver(Frame $frame): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::current() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::current() called on non-object');
        }

        return $receiver->toObject();
    }
}
