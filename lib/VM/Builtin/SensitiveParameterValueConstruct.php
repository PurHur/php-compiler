<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPCompiler\VM\Variable;

/** SensitiveParameterValue::__construct(mixed $value) — VM (#5127). */
final class SensitiveParameterValueConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('SensitiveParameterValue::__construct() expects a value');
        }
        $receiver = SensitiveParamSupport::requireMarkerObject($frame, $frame->calledArgs[0]);
        $value = $frame->calledArgs[1]->resolveIndirect();
        $receiver->getProperty(SensitiveParamSupport::PROP_VALUE)->copyFrom($value);
        $receiver->constructed = true;
    }
}
