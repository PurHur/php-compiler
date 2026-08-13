<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * xml_error_string() — map parser/libxml error code to message (php-src ext/xml/xml.c; #18120, #30651).
 *
 * php-src: ext/xml/xml.stub.php — xml_error_string(int $error_code): ?string
 * Z_PARAM_LONG: soft-null deprecate+coerce outside strict_types; TypeError under strict_types.
 */
final class xml_error_string extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_error_string');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('xml_error_string() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $code = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            0,
            'xml_error_string',
            1,
            'error_code'
        );

        $frame->returnVar->string(VmXml::errorString($code));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_error_string() is not JIT-lowered in this compiler build');
    }
}
