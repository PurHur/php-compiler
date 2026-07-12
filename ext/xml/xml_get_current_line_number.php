<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_get_current_line_number() — parser line diagnostic (php-src ext/xml/xml.c; #18120). */
final class xml_get_current_line_number extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_get_current_line_number');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('xml_get_current_line_number() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $parserArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $parserArg->type) {
            throw new \TypeError('xml_get_current_line_number(): Argument #1 ($parser) must be of type XMLParser');
        }

        $frame->returnVar->int(VmXml::getCurrentLineNumber($parserArg->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_get_current_line_number() is not JIT-lowered in this compiler build');
    }
}
