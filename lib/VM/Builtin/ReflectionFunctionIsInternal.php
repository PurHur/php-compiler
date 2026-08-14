<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isInternal() — VM (#6678). */
final class ReflectionFunctionIsInternal extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInternal');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_isInternal — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'isInternal');
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionInternalFunction($receiver));
        }
    }
}
