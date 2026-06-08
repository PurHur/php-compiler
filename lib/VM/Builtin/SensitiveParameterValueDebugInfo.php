<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\SensitiveParamSupport;

/** SensitiveParameterValue::__debugInfo() — hide wrapped value (Zend #5127). */
final class SensitiveParameterValueDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        SensitiveParamSupport::requireMarkerObject($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->newArray();
        }
    }
}
