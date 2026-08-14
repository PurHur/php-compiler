<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getShortName() — VM (#15274, ext/reflection/php_reflection.c). */
final class ReflectionClassGetShortName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getShortName');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::getShortName', 0);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::shortClassNameFromReflection($receiver));
        }
    }
}
