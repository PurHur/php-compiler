<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionAttribute::isRepeated() — VM (#6912, ext/reflection/php_reflection.c). */
final class ReflectionAttributeIsRepeated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isRepeated');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        $flagVar = $receiver->getProperty(ReflectionSupport::PROP_ATTR_IS_REPEATED)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($flagVar->toBool());
        }
    }
}
