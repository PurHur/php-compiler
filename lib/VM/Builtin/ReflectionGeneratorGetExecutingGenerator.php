<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionGenerator::getExecutingGenerator() — VM (#5964, ext/reflection/php_reflection.c). */
final class ReflectionGeneratorGetExecutingGenerator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExecutingGenerator');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $gen = GeneratorTrace::generatorStateFromReflection($receiver);
        GeneratorTrace::requireActiveGenerator($gen);
        $target = $receiver->getProperty(ReflectionSupport::PROP_GENERATOR_TARGET)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($target);
        }
    }
}
