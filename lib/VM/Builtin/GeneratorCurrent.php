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
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::current() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $calledArgs[0] is $this (#30907)
        $userArgCount = \count($frame->calledArgs) - 1;
        if (0 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'Generator::current() expects exactly 0 arguments, %d given',
                $userArgCount
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::current() called on non-object');
        }
        $gen = GeneratorGetReturn::requireGeneratorState($receiver->toObject());
        GeneratorGetReturn::ensureStarted($gen);
        if (null === $frame->returnVar) {
            return;
        }
        if ($gen->hasCurrent) {
            // FUNCCALL result slots may alias generator state storage (#1885, #18183, #18184).
            $staging = new Variable();
            $staging->duplicateFrom($gen->currentSnapshot);
            $frame->returnVar->copyFrom($staging);
        } else {
            $frame->returnVar->null();
        }
    }
}
