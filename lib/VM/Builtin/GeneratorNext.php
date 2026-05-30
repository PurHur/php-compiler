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
        $gen = GeneratorGetReturn::requireGeneratorState(self::receiver($frame));
        if ($gen->done) {
            return;
        }
        $null = new Variable();
        $null->null();
        $gen->vm->resumeGenerator($gen, $null);
    }

    private static function receiver(Frame $frame): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::next() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::next() called on non-object');
        }

        return $receiver->toObject();
    }
}
