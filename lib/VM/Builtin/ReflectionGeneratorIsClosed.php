<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;

/**
 * ReflectionGenerator::isClosed(): bool — VM (#22242, php-src zim_reflection_generator_isClosed).
 *
 * php-src returns true when generator->execute_data is NULL (finished / force-closed).
 * Maps to {@see \PHPCompiler\VM\GeneratorState::$done}.
 */
final class ReflectionGeneratorIsClosed extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isClosed');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $gen = GeneratorTrace::generatorStateFromReflection($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($gen->done);
    }
}
