<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionGenerator::getThis(): ?object — VM (#22067, ext/reflection/php_reflection.c). */
final class ReflectionGeneratorGetThis extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getThis');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionGenerator_getThis — ZEND_PARSE_PARAMETERS_NONE (#30927)
        $this->requireExactUserArgCount($frame, 'ReflectionGenerator::getThis', 0);
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $gen = GeneratorTrace::generatorStateFromReflection($receiver);
        $thisVar = GeneratorTrace::boundThis($gen);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $thisVar) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom($thisVar->resolveIndirect());
    }
}
