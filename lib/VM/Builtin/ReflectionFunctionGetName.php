<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getName() — VM (#3354, #3355). */
final class ReflectionFunctionGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionFunctionAbstract::getName', 0);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::functionNameFromReflection($receiver));
        }
    }
}
