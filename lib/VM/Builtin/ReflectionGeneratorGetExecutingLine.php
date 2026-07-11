<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionGenerator::getExecutingLine() — VM (#5964, ext/reflection/php_reflection.c). */
final class ReflectionGeneratorGetExecutingLine extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExecutingLine');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $gen = GeneratorTrace::generatorStateFromReflection($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(GeneratorTrace::executingLine($gen));
        }
    }
}
