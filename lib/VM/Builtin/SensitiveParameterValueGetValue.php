<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\SensitiveParamSupport;

/** SensitiveParameterValue::getValue() — VM (#5127). */
final class SensitiveParameterValueGetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getValue');
    }

    public function execute(Frame $frame): void
    {
        $receiver = SensitiveParamSupport::requireMarkerObject($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                $receiver->getProperty(SensitiveParamSupport::PROP_VALUE)->resolveIndirect()
            );
        }
    }
}
