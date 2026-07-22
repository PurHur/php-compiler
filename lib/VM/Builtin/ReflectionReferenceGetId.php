<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionReference::getId() — VM (ext/reflection/php_reflection.c SHA1(ref||key), #22065). */
final class ReflectionReferenceGetId extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getId');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionReference($frame, $frame->calledArgs[0]);
        $id = $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_REFERENCE_ID)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($id);
        }
    }
}
