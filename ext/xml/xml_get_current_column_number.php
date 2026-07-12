<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_get_current_column_number() — parser column diagnostic (php-src ext/xml/xml.c; #18120). */
final class xml_get_current_column_number extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_get_current_column_number');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('xml_get_current_column_number() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_get_current_column_number', 1);

        $frame->returnVar->int(VmXml::getCurrentColumnNumber($parser->id));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_get_current_column_number() is not JIT-lowered in this compiler build');
    }
}
