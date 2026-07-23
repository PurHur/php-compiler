<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionGenerator::__construct(Generator $generator) — VM (#5964, ext/reflection/php_reflection.c). */
final class ReflectionGeneratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 1) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionGenerator', 1, $argc);
        }
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $generator = GeneratorTrace::requireGeneratorObject(
            $frame->calledArgs[1],
            'ReflectionGenerator::__construct',
            1
        );
        $wrapped = new Variable();
        $wrapped->object($generator);
        $receiver->getProperty(ReflectionSupport::PROP_GENERATOR_TARGET)->copyFrom($wrapped);
        $receiver->constructed = true;
    }
}
