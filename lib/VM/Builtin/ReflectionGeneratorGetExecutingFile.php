<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\GeneratorTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionGenerator::getExecutingFile() — VM (#5964, ext/reflection/php_reflection.c). */
final class ReflectionGeneratorGetExecutingFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExecutingFile');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionGenerator_getExecutingFile — ZEND_PARSE_PARAMETERS_NONE (#30927)
        $this->requireExactUserArgCount($frame, 'ReflectionGenerator::getExecutingFile', 0);
        $receiver = ReflectionSupport::requireReflectionGenerator($frame, $frame->calledArgs[0]);
        $gen = GeneratorTrace::generatorStateFromReflection($receiver);
        $file = GeneratorTrace::executingFile($gen);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $file) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($file);
        }
    }
}
