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
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SensitiveParameterValue::getValue() called without $this');
        }
        // php-src: Zend/zend_attributes.stub.php — getValue(): mixed; ZEND_PARSE_PARAMETERS(0) (#30867)
        $this->requireExactUserArgCount($frame, 'SensitiveParameterValue::getValue', 0);
        $receiver = SensitiveParamSupport::requireMarkerObject($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                $receiver->getProperty(SensitiveParamSupport::PROP_VALUE)->resolveIndirect()
            );
        }
    }
}
