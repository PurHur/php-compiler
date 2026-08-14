<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::isIterateable() — VM (#18297, ext/reflection/php_reflection.c). */
final class ReflectionClassIsIterateable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isIterateable');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_isIterateable — ZEND_PARSE_PARAMETERS (0 args) (#31126)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::isIterateable', 0);
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::reflectionClassIsIterateable($entry, $ctx));
        }
    }
}
