<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getName() — VM (#9878 companion, ext/reflection/php_reflection.c). */
final class ReflectionClassGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::getName', 0);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::classNameFromReflection($receiver));
        }
    }
}
