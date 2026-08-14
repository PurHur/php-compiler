<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isIterable() — VM (#22117, ext/reflection/php_reflection.c zim_reflection_class_isIterable). */
final class ReflectionClassIsIterable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isIterable');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_isIterable — ZEND_PARSE_PARAMETERS (0 args) (#31126)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::isIterable', 0);
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::reflectionClassIsIterateable($entry, $ctx));
        }
    }
}
