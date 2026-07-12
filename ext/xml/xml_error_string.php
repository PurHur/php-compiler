<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_error_string() — map parser/libxml error code to message (php-src ext/xml/xml.c; #18120). */
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

        $codeArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $codeArg->type) {
            throw new \TypeError('xml_error_string(): Argument #1 ($code) must be of type int');
        }

        $frame->returnVar->string(VmXml::errorString($codeArg->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_error_string() is not JIT-lowered in this compiler build');
    }
}
