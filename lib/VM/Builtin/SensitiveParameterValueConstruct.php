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
        // php-src: Zend/zend_attributes.stub.php — __construct(mixed $value); $calledArgs[0] is $this (#30867)
        $this->requireExactUserArgCount($frame, 'SensitiveParameterValue::__construct', 1);
        $receiver = SensitiveParamSupport::requireMarkerObject($frame, $frame->calledArgs[0]);
        $value = $frame->calledArgs[1]->resolveIndirect();
        $receiver->getProperty(SensitiveParamSupport::PROP_VALUE)->copyFrom($value);
        $receiver->constructed = true;
    }
}
