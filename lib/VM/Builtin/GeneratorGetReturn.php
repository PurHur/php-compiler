<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Generator::getReturn() — return value after generator close (#3350). */
final class GeneratorGetReturn extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReturn');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Generator::getReturn() called without $this');
        }
        // php-src: Zend/zend_generators.c — ZEND_PARSE_PARAMETERS (0 args); $calledArgs[0] is $this (#30866)
        $userArgCount = \count($frame->calledArgs) - 1;
        if (0 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'Generator::getReturn() expects exactly 0 arguments, %d given',
                $userArgCount
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Generator::getReturn() called on non-object');
        }
        $object = $receiver->toObject();
        $gen = self::requireGeneratorState($object);
        self::ensureStarted($gen);
        if (!$gen->hasReturned) {
            throw new \Exception(
                "Cannot get return value of a generator that hasn't returned"
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($gen->returnValue);
    }

    public static function requireGeneratorState(ObjectEntry $object): GeneratorState
    {
        $gen = $object->generatorState;
        if (null === $gen) {
            throw new \LogicException('Expected Generator instance');
        }

        return $gen;
    }

    /**
     * Zend zend_generator_ensure_initialized — open to first yield and mark AT_FIRST_YIELD (#23713).
     */
    public static function ensureStarted(GeneratorState $gen): void
    {
        if ($gen->done || $gen->hasCurrent || $gen->started || $gen->hasReturned) {
            return;
        }

        $gen->vm->resumeGenerator($gen);
        $gen->atFirstYield = true;
    }
}
