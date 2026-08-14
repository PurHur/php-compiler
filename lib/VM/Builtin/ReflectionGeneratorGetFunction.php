<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionGenerator::getFunction() — VM (#5964, ext/reflection/php_reflection.c). */
final class ReflectionGeneratorGetFunction extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFunction');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionGenerator_getFunction — ZEND_PARSE_PARAMETERS_NONE (#30927)
        $this->requireExactUserArgCount($frame, 'ReflectionGenerator::getFunction', 0);
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $gen = GeneratorTrace::generatorStateFromReflection($receiver);
        GeneratorTrace::requireActiveGenerator($gen);
        $reflection = ReflectionSupport::reflectionFunctionFromGenerator($ctx, $gen);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($reflection);
        }
    }
}
