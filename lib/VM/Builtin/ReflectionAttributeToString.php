<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/**
 * ReflectionAttribute::__toString() — VM (#22420, ext/reflection/php_reflection.c).
 *
 * php-src: ZEND_METHOD(ReflectionAttribute, __toString) → "Attribute [ Name ]\n".
 */
final class ReflectionAttributeToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(ReflectionSupport::attributeReflectionToString($receiver));
        }
    }
}
