<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isUserDefined() — VM (#6678). */
final class ReflectionFunctionIsUserDefined extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isUserDefined');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_isUserDefined — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'isUserDefined');
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(!ReflectionSupport::isReflectionInternalFunction($receiver));
        }
    }
}
