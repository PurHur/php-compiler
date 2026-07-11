<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_get_error_code() — parser diagnostic code (php-src ext/xml/xml.c; #13295). */
final class xml_get_error_code extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_get_error_code');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('xml_get_error_code() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $parserArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $parserArg->type) {
            throw new \TypeError('xml_get_error_code(): Argument #1 ($parser) must be of type XMLParser');
        }

        $frame->returnVar->int(VmXml::getErrorCode($parserArg->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_get_error_code() is not JIT-lowered in this compiler build');
    }
}
